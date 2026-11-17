<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;

/**
 * A member company's contact that stands for another member of the group (D16.7): lines with this contact on an account
 * flagged intercompany are intragroup items with that member.
 *
 * @property string $id
 * @property string $group_id
 * @property string $member_id
 * @property string $company_id
 * @property string $contact_id
 * @property string $counterparty_member_id
 */
class ConsolidationIcContact extends BaseModel {}
