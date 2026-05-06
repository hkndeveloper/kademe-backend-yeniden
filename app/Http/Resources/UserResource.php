<?php

namespace App\Http\Resources;

use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $authorization = null;

        if ($request->user()?->id === $this->id) {
            $authorization = app(PermissionResolver::class)->resolve($this->resource);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'surname' => $this->surname,
            'email' => $request->user()?->id === $this->id ? $this->email : $this->maskEmail($this->email),
            'must_change_password' => $request->user()?->id === $this->id ? (bool) $this->must_change_password : null,
            // Sadece yetkili kişiler maskesiz telefonu görebilir
            'phone' => ($request->user()?->id === $this->id || ($request->user() && ($request->user()->hasRole('super_admin') || $request->user()->hasRole('coordinator'))))
                ? $this->phone
                : $this->maskPhone($this->phone),
            'role' => $this->role,
            'status' => $this->status,
            'university' => $this->university,
            'department' => $this->department,
            'class_year' => $this->class_year,
            // İlişkiler
            'profile' => $this->whenLoaded('profile'),
            'roles' => $this->whenLoaded('roles'),
            'joined_at' => $this->created_at->format('Y-m-d H:i:s'),
            'effective_permissions' => $authorization['effective_permissions'] ?? [],
            'role_permissions' => $authorization['role_permissions'] ?? [],
            'permission_scopes' => $authorization['scopes'] ?? [],
            'permission_overrides' => $authorization['direct_overrides'] ?? [],
            'authorization_context' => $authorization['contexts'] ?? [],
        ];
    }

    private function maskEmail($email)
    {
        if (!$email) return null;
        $parts = explode("@", $email);
        $name = implode('@', array_slice($parts, 0, count($parts)-1));
        $len  = floor(strlen($name)/2);
        return substr($name, 0, $len) . str_repeat('*', $len) . "@" . end($parts);
    }

    private function maskPhone($phone)
    {
        if (!$phone) return null;
        return substr($phone, 0, 4) . '***' . substr($phone, -2);
    }
}
