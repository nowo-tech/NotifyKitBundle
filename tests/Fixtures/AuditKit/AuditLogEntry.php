<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\AuditKit;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;

/**
 * The host's AuditKit action log entity of the AuditKit bridge test (ids, no user relation).
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
class AuditLogEntry extends AbstractIdActionLog
{
}
