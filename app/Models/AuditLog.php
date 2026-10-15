<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Str;

/**
 * Jejak aksi sensitif. Ditulis lewat AuditLog::record() dari controller —
 * sengaja eksplisit (bukan observer otomatis) supaya yang tercatat adalah
 * NIAT pengguna ("kirim ulang 120 log") alih-alih ribuan perubahan baris.
 *
 * Tak pernah menyimpan password / nilai rahasia; panggil dengan nilai yang
 * sudah di-mask (lihat SettingController, UserController).
 */
class AuditLog extends Model
{
    use Prunable;

    /** Log lebih tua dari ini dibuang oleh `model:prune` (jadwal harian). */
    public const RETENTION_DAYS = 365;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'user_name', 'action', 'target_type', 'target_id', 'target_label',
        'changes', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Catat satu aksi. Aktor default = user yang sedang login; untuk login
     * gagal, kirim $actorName (email yang dicoba) dengan $actor null.
     *
     * @param  Model|array{type: string, id: mixed, label?: string}|null  $target
     */
    public static function record(
        string $action,
        Model|array|null $target = null,
        array $changes = [],
        ?User $actor = null,
        ?string $actorName = null,
    ): self {
        $request = request();
        $actor ??= $request?->user();

        [$type, $id, $label] = match (true) {
            $target instanceof Model => [
                Str::snake(class_basename($target)),
                $target->getKey(),
                $target->getAttribute('name') ?? $target->getAttribute('serial_number') ?? $target->getAttribute('key'),
            ],
            is_array($target) => [$target['type'], $target['id'] ?? null, $target['label'] ?? null],
            default => [null, null, null],
        };

        return static::create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name ?? $actorName,
            'action' => $action,
            'target_type' => $type,
            'target_id' => $id === null ? null : (string) $id,
            'target_label' => $label === null ? null : Str::limit((string) $label, 197),
            'changes' => $changes ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 252) : null,
        ]);
    }

    /**
     * Field yang berubah saja: ['field' => ['from' => .., 'to' => ..]].
     * Array dibandingkan tanpa memedulikan urutan.
     */
    public static function diff(array $before, array $after): array
    {
        $normalize = function ($v) {
            if (is_array($v)) {
                sort($v);
            }

            return $v;
        };

        $changes = [];
        foreach ($after as $field => $to) {
            $from = $before[$field] ?? null;
            if ($normalize($from) != $normalize($to)) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
