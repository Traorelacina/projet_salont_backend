<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'device_id',
        'entity_type',
        'entity_id',
        'action',
        'data_before',
        'data_after',
        'statut',
        'message_erreur',
        'date_sync',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data_before' => 'array',
        'data_after' => 'array',
        'date_sync' => 'datetime',
    ];

    /**
     * Scope pour filtrer par device.
     */
    public function scopeByDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope pour filtrer par type d'entité.
     */
    public function scopeByEntityType($query, string $type)
    {
        return $query->where('entity_type', $type);
    }

    /**
     * Scope pour obtenir uniquement les conflits.
     */
    public function scopeConflits($query)
    {
        return $query->where('statut', 'conflit');
    }

    /**
     * Scope pour obtenir uniquement les échecs.
     */
    public function scopeEchecs($query)
    {
        return $query->where('statut', 'echec');
    }

    /**
     * Scope pour obtenir uniquement les succès.
     */
    public function scopeSucces($query)
    {
        return $query->where('statut', 'succes');
    }

    /**
     * Marquer comme succès.
     */
    public function marquerSucces(): void
    {
        $this->update(['statut' => 'succes']);
    }

    /**
     * Marquer comme échec.
     */
    public function marquerEchec(string $message): void
    {
        $this->update([
            'statut' => 'echec',
            'message_erreur' => $message,
        ]);
    }

    /**
     * Marquer comme conflit.
     */
    public function marquerConflit(string $message): void
    {
        $this->update([
            'statut' => 'conflit',
            'message_erreur' => $message,
        ]);
    }
}
