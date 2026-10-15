<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in user's own view of themselves. */
class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enabled = $this->hasTwoFactorEnabled();
        $verified = (bool) $request->session()->get('mfa_verified');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'roles' => $this->roleSlugs(),
            'permissions' => $this->permissionSlugs(),
            'two_factor_enabled' => $enabled,
            'mfa_required' => $this->requiresMfa(),
            'mfa_setup_required' => $this->requiresMfa() && ! $enabled,
            'mfa_verified' => $verified,
            'profile' => $this->whenLoaded('profile', fn () => $this->profile ? [
                'phone' => $this->profile->phone, 'bio' => $this->profile->bio, 'country' => $this->profile->country,
                'timezone' => $this->profile->timezone, 'has_photo' => (bool) $this->profile->photo_path,
            ] : null),
        ];
    }
}
