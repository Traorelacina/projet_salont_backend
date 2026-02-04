<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'password',
        'role',
        'actif',
        'specialite',
        'commission',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
            'commission' => 'decimal:2',
        ];
    }

    /**
     * Les rôles disponibles.
     */
    const ROLES = [
        'admin' => 'Administrateur',
        'manager' => 'Manager',
        'caissier' => 'Caissier',
        'coiffeur' => 'Coiffeur',
    ];

    /**
     * Les spécialités disponibles pour les coiffeurs.
     */
    const SPECIALITES = [
        'coiffure_homme' => 'Coiffure Homme',
        'coiffure_femme' => 'Coiffure Femme',
        'barbe' => 'Barbe',
        'esthetique' => 'Esthétique',
        'maquillage' => 'Maquillage',
        'manucure' => 'Manucure',
        'epilation' => 'Épilation',
        'soin_visage' => 'Soin Visage',
        'soin_corps' => 'Soin Corps',
        'massage' => 'Massage',
        'extension' => 'Extension',
        'coloration' => 'Coloration',
        'lissage' => 'Lissage',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot()
    {
        parent::boot();

        // Pour les coiffeurs, l'email n'est pas obligatoire
        static::saving(function ($user) {
            if ($user->role === 'coiffeur' && empty($user->email)) {
                $user->email = null;
            }
        });

        // Définir des valeurs par défaut pour les coiffeurs
        static::creating(function ($user) {
            if ($user->role === 'coiffeur') {
                if (empty($user->actif)) {
                    $user->actif = true;
                }
                if (empty($user->commission)) {
                    $user->commission = 30.00; // 30% de commission par défaut
                }
            }
        });
    }

    /**
     * Vérifie si l'utilisateur a un rôle spécifique.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Vérifie si l'utilisateur est un administrateur.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Vérifie si l'utilisateur est un manager.
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Vérifie si l'utilisateur est un caissier.
     */
    public function isCaissier(): bool
    {
        return $this->role === 'caissier';
    }

    /**
     * Vérifie si l'utilisateur est un coiffeur.
     */
    public function isCoiffeur(): bool
    {
        return $this->role === 'coiffeur';
    }

    /**
     * Vérifie si l'utilisateur peut gérer les utilisateurs.
     * Seul l'admin peut gérer les utilisateurs.
     */
    public function canManageUsers(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Vérifie si l'utilisateur peut voir les statistiques.
     * Admin et Manager peuvent voir les stats.
     */
    public function canViewStatistics(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /**
     * Vérifie si l'utilisateur peut gérer les prestations.
     * Admin et Manager peuvent gérer les prestations.
     */
    public function canManagePrestations(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    /**
     * Vérifie si l'utilisateur peut réaliser des prestations.
     * Coiffeurs et certains autres rôles selon besoin.
     */
    public function canPerformServices(): bool
    {
        return in_array($this->role, ['coiffeur', 'admin', 'manager']);
    }

    /**
     * Vérifie si l'utilisateur a besoin d'un compte de connexion.
     * Les coiffeurs n'ont pas besoin de compte.
     */
    public function needsAccount(): bool
    {
        return $this->role !== 'coiffeur';
    }

    /**
     * Vérifie si l'utilisateur a un compte de connexion.
     */
    public function hasAccount(): bool
    {
        return !empty($this->email) && !empty($this->password);
    }

    /**
     * Scope pour filtrer les utilisateurs actifs.
     */
    public function scopeActif($query)
    {
        return $query->where('actif', true);
    }

    /**
     * Scope pour filtrer par rôle.
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope pour filtrer les coiffeurs.
     */
    public function scopeCoiffeurs($query)
    {
        return $query->where('role', 'coiffeur');
    }

    /**
     * Scope pour filtrer les utilisateurs avec compte.
     */
    public function scopeWithAccount($query)
    {
        return $query->whereNotNull('email')->whereNotNull('password');
    }

    /**
     * Scope pour filtrer par spécialité.
     */
    public function scopeBySpecialite($query, string $specialite)
    {
        return $query->where('specialite', $specialite);
    }

    /**
     * Get the prestations assigned to this coiffeur.
     */
    public function prestations(): BelongsToMany
    {
        return $this->belongsToMany(Prestation::class, 'prestation_coiffeur', 'coiffeur_id', 'prestation_id')
            ->withTimestamps()
            ->withPivot(['created_at', 'updated_at']);
    }

    /**
     * Get the passages where this coiffeur worked.
     */
    public function passages()
    {
        if (!$this->isCoiffeur()) {
            return null;
        }
        
        return \Illuminate\Support\Facades\DB::table('passage_prestation')
            ->where('coiffeur_id', $this->id)
            ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
            ->join('clients', 'passages.client_id', '=', 'clients.id')
            ->select(
                'passages.*',
                'clients.nom as client_nom',
                'clients.prenom as client_prenom',
                'passage_prestation.prix_applique',
                'passage_prestation.quantite'
            )
            ->orderBy('passages.date_passage', 'desc');
    }

    /**
     * Obtenir le nom complet de l'utilisateur.
     */
    public function getNomCompletAttribute(): string
    {
        return "{$this->prenom} {$this->nom}";
    }

    /**
     * Obtenir le label du rôle.
     */
    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /**
     * Obtenir le label de la spécialité.
     */
    public function getSpecialiteLabelAttribute(): string
    {
        return self::SPECIALITES[$this->specialite] ?? $this->specialite ?? 'Non spécifié';
    }

    /**
     * Obtenir les statistiques du coiffeur.
     */
    public function getStatistiquesAttribute(): ?array
    {
        if (!$this->isCoiffeur()) {
            return null;
        }

        $stats = \Illuminate\Support\Facades\DB::table('passage_prestation')
            ->where('coiffeur_id', $this->id)
            ->selectRaw('
                COUNT(DISTINCT passage_id) as total_passages,
                COUNT(*) as total_prestations,
                SUM(prix_applique * quantite) as chiffre_affaires,
                SUM(quantite) as total_quantites,
                AVG(prix_applique) as prix_moyen
            ')
            ->first();

        // Statistiques par mois
        $statsMensuelles = \Illuminate\Support\Facades\DB::table('passage_prestation')
            ->where('coiffeur_id', $this->id)
            ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
            ->selectRaw('
                DATE_FORMAT(passages.date_passage, "%Y-%m") as mois,
                COUNT(*) as nombre_prestations,
                SUM(prix_applique * quantite) as chiffre_mensuel
            ')
            ->groupBy('mois')
            ->orderBy('mois', 'desc')
            ->limit(6)
            ->get();

        return [
            'total_passages' => $stats->total_passages ?? 0,
            'total_prestations' => $stats->total_prestations ?? 0,
            'chiffre_affaires' => $stats->chiffre_affaires ?? 0,
            'total_quantites' => $stats->total_quantites ?? 0,
            'prix_moyen' => $stats->prix_moyen ?? 0,
            'commission_estimee' => ($stats->chiffre_affaires ?? 0) * ($this->commission / 100),
            'commission_pourcentage' => $this->commission,
            'stats_mensuelles' => $statsMensuelles,
        ];
    }

    /**
     * Obtenir le nombre total de prestations réalisées par ce coiffeur.
     */
    public function getNombrePrestationsAttribute(): int
    {
        if (!$this->isCoiffeur()) {
            return 0;
        }

        return \Illuminate\Support\Facades\DB::table('passage_prestation')
            ->where('coiffeur_id', $this->id)
            ->count();
    }

    /**
     * Obtenir le chiffre d'affaires total généré par ce coiffeur.
     */
    public function getChiffreAffairesAttribute(): float
    {
        if (!$this->isCoiffeur()) {
            return 0;
        }

        $total = \Illuminate\Support\Facades\DB::table('passage_prestation')
            ->where('coiffeur_id', $this->id)
            ->selectRaw('SUM(prix_applique * quantite) as total')
            ->first();

        return (float) ($total->total ?? 0);
    }

    /**
     * Obtenir la commission estimée pour ce coiffeur.
     */
    public function getCommissionEstimeeAttribute(): float
    {
        if (!$this->isCoiffeur() || !$this->commission) {
            return 0;
        }

        return $this->chiffre_affaires * ($this->commission / 100);
    }

    /**
     * Obtenir la liste des prestations que ce coiffeur peut réaliser.
     */
    public function getPrestationsDisponiblesAttribute()
    {
        if (!$this->isCoiffeur()) {
            return collect();
        }

        return $this->prestations()
            ->where('actif', true)
            ->orderBy('libelle')
            ->get();
    }

    /**
     * Obtenir les 5 derniers passages de ce coiffeur.
     */
    public function getDerniersPassagesAttribute()
    {
        if (!$this->isCoiffeur()) {
            return collect();
        }

        $passages = \Illuminate\Support\Facades\DB::table('passage_prestation')
            ->where('coiffeur_id', $this->id)
            ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
            ->join('clients', 'passages.client_id', '=', 'clients.id')
            ->join('prestations', 'passage_prestation.prestation_id', '=', 'prestations.id')
            ->select(
                'passages.id',
                'passages.date_passage',
                'passages.est_gratuit',
                'clients.nom as client_nom',
                'clients.prenom as client_prenom',
                'prestations.libelle as prestation_libelle',
                'passage_prestation.prix_applique',
                'passage_prestation.quantite'
            )
            ->orderBy('passages.date_passage', 'desc')
            ->limit(5)
            ->get();

        return $passages->map(function ($passage) {
            $passage->sous_total = $passage->prix_applique * $passage->quantite;
            $passage->client_nom_complet = $passage->client_prenom . ' ' . $passage->client_nom;
            return $passage;
        });
    }

    /**
     * Activer/désactiver ce coiffeur.
     */
    public function toggleActif(): bool
    {
        if (!$this->isCoiffeur()) {
            return false;
        }

        $this->actif = !$this->actif;
        return $this->save();
    }

    /**
     * Mettre à jour la commission.
     */
    public function updateCommission(float $commission): bool
    {
        if (!$this->isCoiffeur() || $commission < 0 || $commission > 100) {
            return false;
        }

        $this->commission = $commission;
        return $this->save();
    }

    /**
     * Assigne une prestation à ce coiffeur.
     */
    public function assignPrestation(int $prestationId): bool
    {
        if (!$this->isCoiffeur()) {
            return false;
        }

        try {
            $this->prestations()->syncWithoutDetaching([$prestationId]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Retire une prestation de ce coiffeur.
     */
    public function removePrestation(int $prestationId): bool
    {
        if (!$this->isCoiffeur()) {
            return false;
        }

        try {
            $this->prestations()->detach($prestationId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}