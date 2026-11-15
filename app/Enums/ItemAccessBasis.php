<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a user may reach a restricted item. A managed basis is the same right
 * seen from a group leader: it inherits the participant's rights but never
 * lets the leader change who the item is shared with.
 */
enum ItemAccessBasis: string
{
    case Admin = 'admin';
    case Reporter = 'reporter';
    case Assignee = 'assignee';
    case Recipient = 'to';
    case Observer = 'cc';
    case GroupMember = 'group_member';
    case ProjectManager = 'project_manager';
    case ManagedReporter = 'managed:reporter';
    case ManagedAssignee = 'managed:assignee';
    case ManagedRecipient = 'managed:to';
    case ManagedObserver = 'managed:cc';
    case ManagedGroupMember = 'managed:group_member';

    public function isManaged(): bool
    {
        return str_starts_with($this->value, 'managed:');
    }

    /**
     * The participant basis a managed basis inherits, or itself.
     */
    public function participant(): self
    {
        return match ($this) {
            self::ManagedReporter => self::Reporter,
            self::ManagedAssignee => self::Assignee,
            self::ManagedRecipient => self::Recipient,
            self::ManagedObserver => self::Observer,
            self::ManagedGroupMember => self::GroupMember,
            default => $this,
        };
    }

    /**
     * Bases that may change every field of the item.
     */
    public function allowsFullUpdate(): bool
    {
        return in_array($this->participant(), [self::Admin, self::Reporter, self::ProjectManager], true);
    }

    /**
     * The assignee may move the workflow and hand the item on, nothing more.
     */
    public function allowsLimitedUpdate(): bool
    {
        return $this->participant() === self::Assignee;
    }

    public function allowsDelete(): bool
    {
        return in_array($this->participant(), [self::Admin, self::Reporter, self::ProjectManager], true);
    }

    /**
     * Only the reporter and an administrator decide who else sees the item.
     */
    public function allowsAccessManagement(): bool
    {
        return $this === self::Admin || $this === self::Reporter;
    }
}
