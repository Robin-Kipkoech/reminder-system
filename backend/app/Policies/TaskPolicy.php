<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;

class TaskPolicy
{
    /**
     * Visibility itself is enforced by the visibleTo scope, so a task that is
     * not visible never reaches the policy: the controller answers 404.
     */
    public function view(User $user, Task $task): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Task $task): bool
    {
        return $task->canBeEditedBy($user);
    }

    public function updateStatus(User $user, Task $task): bool
    {
        return $task->canBeEditedBy($user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->canBeDeletedBy($user);
    }

    public function addComment(User $user, Task $task): bool
    {
        return true;
    }

    public function deleteComment(User $user, Task $task, TaskComment $comment): bool
    {
        return $comment->canBeDeletedBy($user);
    }
}
