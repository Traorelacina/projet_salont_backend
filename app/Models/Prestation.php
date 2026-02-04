<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Prestation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'libelle',
        'prix',
        'description',
        'actif',
        'ordre',
        'duree_estimee',
        'specialite',
        'device_id',
        'synced_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'prix' => 'decimal:2',
        'actif' => 'boolean',
        'ordre' => 'integer',
        'duree_estimee' => 'integer',
        'synced_at' => 'datetime',
    ];

    /**
     * Les spécialités disponibles pour les prestations.
     */
    const SPECIALITES = [
        'coiffure' => 'Coiffure',
        'barbe' => 'Barbe',
        'soin' => 'Soin',
        'esthetique' => 'Esthétique',
        'maquillage' => 'Maquillage',
        'manucure' => 'Manucure',
        'epilation' => 'Épilation',
    ];

    /**
     * Get the coiffeurs (users with role 'coiffeur') for the prestation.
     */
    public function coiffeurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'prestation_coiffeur', 'prestation_id', 'coiffeur_id')
            ->where('role', 'coiffeur')
            ->where('actif', true)
            ->withTimestamps()
            ->withPivot(['created_at', 'updated_at']);
    }

    /**
     * Get all coiffeurs (including inactive) for admin purposes.
     */
    public function allCoiffeurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'prestation_coiffeur', 'prestation_id', 'coiffeur_id')
            ->where('role', 'coiffeur')
            ->withTimestamps()
            ->withPivot(['created_at', 'updated_at']);
    }

    /**
     * Get the passages for the prestation.
     */
    public function passages(): BelongsToMany
    {
        return $this->belongsToMany(Passage::class, 'passage_prestation')
            ->withPivot('id', 'prix_applique', 'quantite', 'coiffeur_id', 'created_at', 'updated_at')
            ->withTimestamps();
    }

    /**
     * Get the passage_prestation pivot records.
     */
    public function passagePrestations(): HasManyThrough
    {
        return $this->hasManyThrough(
            PassagePrestation::class,
            Passage::class,
            'id', // Foreign key on passages table
            'passage_id', // Foreign key on passage_prestation table
            'id', // Local key on prestations table
            'id' // Local key on passages table
        );
    }

    /**
     * Get the passages with specific coiffeur.
     */
    public function passagesByCoiffeur(int $coiffeurId): BelongsToMany
    {
        return $this->belongsToMany(Passage::class, 'passage_prestation')
            ->wherePivot('coiffeur_id', $coiffeurId)
            ->withPivot('id', 'prix_applique', 'quantite', 'coiffeur_id', 'created_at', 'updated_at')
            ->withTimestamps();
    }

    /**
     * Scope pour obtenir uniquement les prestations actives.
     */
    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    /**
     * Scope pour trier par ordre.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('ordre')->orderBy('libelle');
    }

    /**
     * Scope pour filtrer par spécialité.
     */
    public function scopeBySpecialite($query, $specialite)
    {
        return $query->where('specialite', $specialite);
    }

    /**
     * Scope pour les prestations disponibles pour un coiffeur.
     */
    public function scopeForCoiffeur($query, $coiffeurId)
    {
        return $query->whereHas('coiffeurs', function ($q) use ($coiffeurId) {
            $q->where('users.id', $coiffeurId);
        });
    }

    /**
     * Check if a coiffeur is associated with this prestation.
     */
    public function hasCoiffeur($coiffeurId): bool
    {
        return $this->coiffeurs()->where('users.id', $coiffeurId)->exists();
    }

    /**
     * Associate a coiffeur with this prestation.
     */
    public function addCoiffeur($coiffeurId): void
    {
        $this->coiffeurs()->syncWithoutDetaching([$coiffeurId]);
    }

    /**
     * Remove a coiffeur from this prestation.
     */
    public function removeCoiffeur($coiffeurId): void
    {
        $this->coiffeurs()->detach($coiffeurId);
    }

    /**
     * Get all coiffeur IDs associated with this prestation.
     */
    public function getCoiffeurIdsAttribute(): array
    {
        return $this->coiffeurs->pluck('id')->toArray();
    }

    /**
     * Obtenir le nombre de fois que cette prestation a été utilisée.
     */
    public function getNombreUtilisationsAttribute(): int
    {
        return $this->passages()->count();
    }

    /**
     * Obtenir le nombre de fois que cette prestation a été utilisée par coiffeur.
     */
    public function getNombreUtilisationsParCoiffeurAttribute(): array
    {
        return $this->passagePrestations()
            ->selectRaw('coiffeur_id, COUNT(*) as count')
            ->whereNotNull('coiffeur_id')
            ->groupBy('coiffeur_id')
            ->with('coiffeur:id,nom,prenom')
            ->get()
            ->mapWithKeys(function ($item) {
                $coiffeurName = $item->coiffeur ? $item->coiffeur->nom . ' ' . $item->coiffeur->prenom : 'Non assigné';
                return [$coiffeurName => $item->count];
            })
            ->toArray();
    }

    /**
     * Obtenir le revenu total généré par cette prestation.
     */
    public function getRevenuTotalAttribute(): float
    {
        return (float) $this->passages()
            ->join('passage_prestation', 'passages.id', '=', 'passage_prestation.passage_id')
            ->where('passage_prestation.prestation_id', $this->id)
            ->sum('passage_prestation.prix_applique');
    }

    /**
     * Obtenir le revenu total par coiffeur.
     */
    public function getRevenuParCoiffeurAttribute(): array
    {
        return $this->passagePrestations()
            ->selectRaw('coiffeur_id, SUM(prix_applique) as total')
            ->whereNotNull('coiffeur_id')
            ->groupBy('coiffeur_id')
            ->with('coiffeur:id,nom,prenom')
            ->get()
            ->mapWithKeys(function ($item) {
                $coiffeurName = $item->coiffeur ? $item->coiffeur->nom . ' ' . $item->coiffeur->prenom : 'Non assigné';
                return [$coiffeurName => (float) $item->total];
            })
            ->toArray();
    }

    /**
     * Get the average duration in minutes.
     */
    public function getDureeMoyenneAttribute(): ?int
    {
        return $this->duree_estimee;
    }

    /**
     * Get the specialite label.
     */
    public function getSpecialiteLabelAttribute(): string
    {
        return self::SPECIALITES[$this->specialite] ?? 'Non spécifié';
    }

    /**
     * Get coiffeurs with their statistics for this prestation.
     */
    public function getCoiffeursWithStatsAttribute()
    {
        return $this->coiffeurs->map(function ($coiffeur) {
            $stats = $this->passagePrestations()
                ->where('coiffeur_id', $coiffeur->id)
                ->selectRaw('COUNT(*) as count, SUM(prix_applique) as revenue')
                ->first();
            
            return [
                'id' => $coiffeur->id,
                'nom' => $coiffeur->nom,
                'prenom' => $coiffeur->prenom,
                'telephone' => $coiffeur->telephone,
                'specialite' => $coiffeur->specialite,
                'nombre_realisations' => $stats ? (int) $stats->count : 0,
                'revenu_total' => $stats ? (float) $stats->revenue : 0,
                'date_association' => $coiffeur->pivot->created_at ?? null,
            ];
        });
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot()
    {
        parent::boot();

        // When creating a new prestation, set default values
        static::creating(function ($prestation) {
            if (empty($prestation->ordre)) {
                $maxOrdre = Prestation::max('ordre') ?? 0;
                $prestation->ordre = $maxOrdre + 1;
            }
            
            if (empty($prestation->actif)) {
                $prestation->actif = true;
            }
        });

        // When deleting, detach all coiffeurs
        static::deleting(function ($prestation) {
            if ($prestation->isForceDeleting()) {
                $prestation->coiffeurs()->detach();
            }
        });
    }
}