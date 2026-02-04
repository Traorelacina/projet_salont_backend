<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom',
        'prenom',
        'code_client',
        'telephone',
        'nombre_passages',
        'derniere_visite',
        'device_id',
        'synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'nombre_passages' => 'integer',
        'derniere_visite' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Get the passages for the client.
     */
    public function passages(): HasMany
    {
        return $this->hasMany(Passage::class);
    }

    /**
     * Get the paiements through passages.
     */
    public function paiements()
    {
        return $this->hasManyThrough(Paiement::class, Passage::class);
    }

    /**
     * Scope pour rechercher un client par téléphone.
     */
    public function scopeByPhone($query, string $phone)
    {
        return $query->where('telephone', 'like', "%{$phone}%");
    }

    /**
     * Scope pour rechercher un client par nom.
     */
    public function scopeByName($query, string $name)
    {
        return $query->where(function($q) use ($name) {
            $q->where('nom', 'like', "%{$name}%")
              ->orWhere('prenom', 'like', "%{$name}%");
        });
    }

    /**
     * Incrémenter le nombre de passages.
     */
    public function incrementerPassages(): void
    {
        $this->increment('nombre_passages');
        $this->update(['derniere_visite' => now()]);
    }

    /**
     * Vérifier si le prochain passage est gratuit.
     */
    public function prochainPassageGratuit(): bool
    {
        $passageGratuit = config('app.fidelite_passages_gratuit', 10);
        return ($this->nombre_passages + 1) % $passageGratuit === 0;
    }

    /**
     * Obtenir le nom complet du client.
     */
    public function getNomCompletAttribute(): string
    {
        return "{$this->prenom} {$this->nom}";
    }

    /**
     * Obtenir le chiffre d'affaires total du client.
     */
    public function getChiffreAffairesTotalAttribute(): float
    {
        return $this->paiements()->sum('montant_paye');
    }
}
