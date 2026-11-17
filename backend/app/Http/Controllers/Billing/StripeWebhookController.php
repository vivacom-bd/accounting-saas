<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Jobs\Billing\ApplyStripeEvent;
use App\Services\Billing\NullBillingGateway;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * Receives Stripe's webhooks (plan Task 12): no session, no CSRF, no tenant; the signature is the only credential.
 * Without a configured secret nothing is accepted (G2). The raw event is stored first (unique Stripe event id) and the
 * job is dispatched for a new event, or for one that arrived before but was never applied; everything runs on the
 * platform connection. The answer is 200 once the event is stored, so Stripe does not redeliver what we already hold.
 */
final class StripeWebhookController extends Controller
{
    public function __construct(private readonly Clock $clock, private readonly IdGenerator $ids) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('cashier.webhook.secret');
        if ($secret === '') {
            throw NullBillingGateway::notConfigured();
        }

        $payload = $request->getContent();
        try {
            $event = Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature'), $secret, (int) config('cashier.webhook.tolerance', 300));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw DomainRuleViolation::single('invalid_signature', 'The webhook signature could not be verified.');
        }

        $db = AdminTransaction::connection();
        $rowId = $this->ids->next();
        $inserted = $db->table('stripe_events')->insertOrIgnore([
            'id' => $rowId,
            'stripe_event_id' => (string) $event->id,
            'type' => (string) $event->type,
            'payload' => $payload,
            'created_at_stripe' => (new DateTimeImmutable('@'.(int) $event->created))->format('Y-m-d H:i:sP'),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:sP'),
        ]);

        if ($inserted === 0) {
            $existing = $db->table('stripe_events')->where('stripe_event_id', (string) $event->id)->whereNull('processed_at')->value('id');
            if (! is_string($existing)) {
                return response()->json(['received' => true]);   // seen and applied: nothing to do
            }
            $rowId = $existing;
        }

        try {
            ApplyStripeEvent::dispatch($rowId);
        } catch (Throwable $e) {
            report($e);   // stored, and the failure is on the row; the webhook must not make Stripe retry forever
        }

        return response()->json(['received' => true]);
    }
}
