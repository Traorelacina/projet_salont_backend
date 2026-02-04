<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Passage extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'numero_passage',
        'est_gratuit',
        'notes',
        'date_passage',
        'device_id',
        'synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'est_gratuit' => 'boolean',
        'date_passage' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Boot method for model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Avant la création, définir le numéro de passage
        static::creating(function ($passage) {
            if (!$passage->numero_passage) {
                $passage->numero_passage = $passage->client->nombre_passages + 1;
            }
            
            // Vérifier si c'est un passage gratuit
            $passageGratuit = config('app.fidelite_passages_gratuit', 10);
            if ($passage->numero_passage % $passageGratuit === 0) {
                $passage->est_gratuit = true;
            }
        });

        // Après la création, incrémenter le compteur du client
        static::created(function ($passage) {
            $passage->client->incrementerPassages();
        });
    }

    /**
     * Get the client that owns the passage.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the paiement for the passage.
     */
    public function paiement(): HasOne
    {
        return $this->hasOne(Paiement::class);
    }

    /**
     * Get the prestations for the passage.
     */
    public function prestations(): BelongsToMany
    {
        return $this->belongsToMany(Prestation::class, 'passage_prestation')
            ->using(PassagePrestation::class)
            ->withPivot('id', 'prix_applique', 'quantite', 'coiffeur_id')
            ->withTimestamps();
    }

    /**
     * Scope pour filtrer par date.
     */
    public function scopeByDate($query, $date)
    {
        return $query->whereDate('date_passage', $date);
    }

    /**
     * Scope pour filtrer par période.
     */
    public function scopeByPeriod($query, $dateDebut, $dateFin)
    {
        return $query->whereBetween('date_passage', [$dateDebut, $dateFin]);
    }

    /**
     * Scope pour obtenir uniquement les passages gratuits.
     */
    public function scopeGratuits($query)
    {
        return $query->where('est_gratuit', true);
    }

    /**
     * Calculer le montant total du passage.
     */
    public function getMontantTotalAttribute(): float
    {
        if ($this->est_gratuit) {
            return 0;
        }

        return $this->prestations->sum(function ($prestation) {
            return $prestation->pivot->prix_applique * $prestation->pivot->quantite;
        });
    }

    /**
     * Obtenir le montant théorique (avant gratuité).
     */
    public function getMontantTheoriqueAttribute(): float
    {
        return $this->prestations->sum(function ($prestation) {
            return $prestation->pivot->prix_applique * $prestation->pivot->quantite;
        });
    }
}