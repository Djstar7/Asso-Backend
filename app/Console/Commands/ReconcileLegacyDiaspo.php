<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reprise (ou suppression) des tables Diaspo « legacy » créées lors de la bascule de
 * schéma unify (integration/unify-dev). Voir DEPLOY_NOTES_UNIFY.md § 1.
 *
 * Contexte : au déploiement, les migrations 2026_04_27_000001/000002 renomment un
 * éventuel ancien schéma Diaspo (14/04, origin) en `diaspo_offers_legacy_preunify` /
 * `diaspo_bookings_legacy_preunify` AVANT de créer le schéma unifié (27/04, upstream).
 * Aucune donnée n'est perdue, mais l'ancien contenu reste hors ligne. Cette commande
 * tranche la décision que le runbook laissait à l'équipe :
 *
 *   php artisan diaspo:reconcile-legacy            # rapport (lecture seule) : que contiennent les tables ?
 *   php artisan diaspo:reconcile-legacy --migrate  # reprend les données (mapping enums + remap des FK offre)
 *   php artisan diaspo:reconcile-legacy --drop      # supprime les tables legacy (cas vide / données de test)
 *
 * Le mapping des enums (ancien schéma → schéma unifié) :
 *   offers.status            : active→approved, full→approved, closed→completed, cancelled→rejected
 *   offers.verification_status : unverified→pending (le reste identique)
 *   bookings.status          : in_transit→confirmed (enum disparu ; le reste identique)
 *   bookings.payment_status  : paid→completed, failed→pending (le reste identique)
 * La colonne `currency` des bookings (présente en 14/04, absente du schéma unifié) est ignorée.
 * Les décimales réduites (15,2→10,2 etc.) n'entraînent aucune perte pour des valeurs réalistes.
 */
class ReconcileLegacyDiaspo extends Command
{
    protected $signature = 'diaspo:reconcile-legacy
        {--migrate : Reprend les données legacy vers les tables unifiées puis archive les tables legacy en *_legacy_migrated}
        {--drop : Supprime les tables Diaspo legacy (*_legacy_preunify et *_legacy_migrated) — pour un contenu vide ou de test}
        {--force : N\'affiche aucune demande de confirmation (scripts / CI)}';

    protected $description = 'Reprend ou supprime les tables Diaspo « legacy » issues de la bascule de schéma unify (voir DEPLOY_NOTES_UNIFY.md).';

    private const OFFER_STATUS_MAP = [
        'active' => 'approved',
        'full' => 'approved',
        'closed' => 'completed',
        'cancelled' => 'rejected',
    ];

    private const OFFER_VERIF_MAP = [
        'unverified' => 'pending',
    ];

    private const BOOKING_STATUS_MAP = [
        'in_transit' => 'confirmed',
    ];

    private const BOOKING_PAYMENT_MAP = [
        'paid' => 'completed',
        'failed' => 'pending',
    ];

    private const LEGACY_OFFERS = 'diaspo_offers_legacy_preunify';
    private const LEGACY_BOOKINGS = 'diaspo_bookings_legacy_preunify';

    public function handle(): int
    {
        $migrate = (bool) $this->option('migrate');
        $drop = (bool) $this->option('drop');

        if ($migrate && $drop) {
            $this->error('Options --migrate et --drop mutuellement exclusives.');
            return Command::FAILURE;
        }

        $hasOffers = Schema::hasTable(self::LEGACY_OFFERS);
        $hasBookings = Schema::hasTable(self::LEGACY_BOOKINGS);

        if (!$hasOffers && !$hasBookings) {
            $this->info('✅ Aucune table Diaspo legacy (*_legacy_preunify). Base fraîche ou reprise déjà faite — rien à faire.');
            return Command::SUCCESS;
        }

        // Rapport d'inventaire (toujours affiché)
        $offerCount = $hasOffers ? DB::table(self::LEGACY_OFFERS)->count() : 0;
        $bookingCount = $hasBookings ? DB::table(self::LEGACY_BOOKINGS)->count() : 0;

        $this->line('<fg=cyan>Inventaire des tables Diaspo legacy :</>');
        $this->table(
            ['Table', 'Existe', 'Lignes'],
            [
                [self::LEGACY_OFFERS, $hasOffers ? 'oui' : 'non', $offerCount],
                [self::LEGACY_BOOKINGS, $hasBookings ? 'oui' : 'non', $bookingCount],
            ]
        );
        if ($hasOffers && $offerCount > 0) {
            $this->breakdown(self::LEGACY_OFFERS, 'status', 'Offres par statut');
        }
        if ($hasBookings && $bookingCount > 0) {
            $this->breakdown(self::LEGACY_BOOKINGS, 'status', 'Réservations par statut');
            $this->breakdown(self::LEGACY_BOOKINGS, 'payment_status', 'Réservations par statut de paiement');
        }
        $this->newLine();

        if (!$migrate && !$drop) {
            $this->info('🔎 Mode rapport (lecture seule).');
            $this->line('  • Données réelles à conserver → <fg=green>--migrate</>');
            $this->line('  • Tables vides / de test        → <fg=yellow>--drop</>');
            return Command::SUCCESS;
        }

        if ($drop) {
            return $this->drop();
        }

        return $this->migrate($hasOffers, $hasBookings, $offerCount, $bookingCount);
    }

    private function breakdown(string $table, string $column, string $title): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }
        $rows = DB::table($table)
            ->select($column, DB::raw('count(*) as n'))
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($r) => [$r->$column ?? '(null)', $r->n])
            ->all();
        $this->line("  <fg=gray>{$title} :</>");
        $this->table(['Valeur', 'Lignes'], $rows);
    }

    private function drop(): int
    {
        $targets = array_filter([
            self::LEGACY_OFFERS,
            self::LEGACY_BOOKINGS,
            'diaspo_offers_legacy_migrated',
            'diaspo_bookings_legacy_migrated',
        ], fn ($t) => Schema::hasTable($t));

        if (empty($targets)) {
            $this->info('Aucune table legacy à supprimer.');
            return Command::SUCCESS;
        }

        $this->warn('⚠️  Suppression DÉFINITIVE des tables : ' . implode(', ', $targets));
        if (!$this->option('force') && !$this->confirm('Confirmer la suppression ?', false)) {
            $this->info('Annulé.');
            return Command::SUCCESS;
        }

        foreach ($targets as $table) {
            Schema::dropIfExists($table);
            $this->line("  • <fg=red>DROP</> {$table}");
        }
        $this->info('✅ Tables legacy supprimées.');
        return Command::SUCCESS;
    }

    private function migrate(bool $hasOffers, bool $hasBookings, int $offerCount, int $bookingCount): int
    {
        if (!Schema::hasTable('diaspo_offers') || !Schema::hasTable('diaspo_bookings')) {
            $this->error('Tables unifiées diaspo_offers/diaspo_bookings absentes. Lancez `php artisan migrate` d\'abord.');
            return Command::FAILURE;
        }

        $this->warn("Reprise : {$offerCount} offre(s) + {$bookingCount} réservation(s) legacy → tables unifiées.");
        $this->line('Les tables legacy seront ensuite archivées en <fg=cyan>*_legacy_migrated</> (conservées, hors reprise future).');
        if (!$this->option('force') && !$this->confirm('Lancer la reprise ?', true)) {
            $this->info('Annulé.');
            return Command::SUCCESS;
        }

        $offerIdMap = [];
        $orphanBookings = 0;

        DB::transaction(function () use (&$offerIdMap, &$orphanBookings, $hasOffers, $hasBookings) {
            if ($hasOffers) {
                $newCols = Schema::getColumnListing('diaspo_offers');
                foreach (DB::table(self::LEGACY_OFFERS)->orderBy('id')->cursor() as $row) {
                    $data = $this->projectRow((array) $row, $newCols, [
                        'status' => self::OFFER_STATUS_MAP,
                        'verification_status' => self::OFFER_VERIF_MAP,
                    ]);
                    unset($data['id']); // nouvel auto-increment
                    $newId = DB::table('diaspo_offers')->insertGetId($data);
                    $offerIdMap[$row->id] = $newId;
                }
            }

            if ($hasBookings) {
                $newCols = Schema::getColumnListing('diaspo_bookings');
                foreach (DB::table(self::LEGACY_BOOKINGS)->orderBy('id')->cursor() as $row) {
                    // Remap de la FK vers l'offre : la ligne legacy pointe l'ancien id.
                    if ($hasOffers && !array_key_exists($row->diaspo_offer_id, $offerIdMap)) {
                        $orphanBookings++;
                        continue; // réservation orpheline (offre absente) → non reprise
                    }
                    $data = $this->projectRow((array) $row, $newCols, [
                        'status' => self::BOOKING_STATUS_MAP,
                        'payment_status' => self::BOOKING_PAYMENT_MAP,
                    ]);
                    unset($data['id']);
                    if ($hasOffers) {
                        $data['diaspo_offer_id'] = $offerIdMap[$row->diaspo_offer_id];
                    }
                    DB::table('diaspo_bookings')->insert($data);
                }
            }

            // Archive : renommer les tables reprises pour qu'une nouvelle exécution soit un no-op.
            if ($hasBookings) {
                Schema::dropIfExists('diaspo_bookings_legacy_migrated');
                Schema::rename(self::LEGACY_BOOKINGS, 'diaspo_bookings_legacy_migrated');
            }
            if ($hasOffers) {
                Schema::dropIfExists('diaspo_offers_legacy_migrated');
                Schema::rename(self::LEGACY_OFFERS, 'diaspo_offers_legacy_migrated');
            }
        });

        $this->info('✅ Reprise terminée : ' . count($offerIdMap) . ' offre(s) reprise(s).');
        if ($orphanBookings > 0) {
            $this->warn("⚠️  {$orphanBookings} réservation(s) orpheline(s) (offre legacy absente) NON reprise(s) — conservées dans diaspo_bookings_legacy_migrated.");
        }
        $this->line('Tables legacy archivées en *_legacy_migrated (supprimables ensuite via --drop).');
        return Command::SUCCESS;
    }

    /**
     * Construit la ligne à insérer : ne garde que les colonnes présentes dans la table
     * cible (intersection legacy ∩ unifié) et applique les mappings d'enum fournis.
     * Les colonnes de la cible absentes de la source (ex. deleted_at, payment_method)
     * ne sont pas positionnées → valeur par défaut du schéma.
     *
     * @param array<string,mixed> $source
     * @param string[] $targetCols
     * @param array<string,array<string,string>> $enumMaps  colonne => [ancienne valeur => nouvelle]
     * @return array<string,mixed>
     */
    private function projectRow(array $source, array $targetCols, array $enumMaps): array
    {
        $data = [];
        foreach ($targetCols as $col) {
            if (!array_key_exists($col, $source)) {
                continue;
            }
            $value = $source[$col];
            if (isset($enumMaps[$col]) && $value !== null && isset($enumMaps[$col][$value])) {
                $value = $enumMaps[$col][$value];
            }
            $data[$col] = $value;
        }
        return $data;
    }
}
