<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A message sent through the contact form on /legal/contact. */
#[Fillable(['name', 'email', 'subject', 'message', 'ip_address', 'user_agent', 'handled_at'])]
class ContactMessage extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
