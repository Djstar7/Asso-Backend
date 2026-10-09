<?php

namespace App\Console\Commands;

use App\Models\WalletTransaction;
use App\Support\Translation\ContentLocale;
use Illuminate\Console\Command;

/**
 * Opérations du portefeuille enregistrées avant les libellés traduisibles :
 * on reconnaît la phrase française d'après les modèles de
 * wallet.transactions (lang/fr.json) et on range sa clé et ses valeurs dans
 * metadata.label. La colonne description n'est pas modifiée.
 */
class BackfillWalletTransactionLabels extends Command
{
    protected $signature = 'wallet:backfill-labels {--dry-run : Affiche ce qui serait repris sans rien écrire}';

    protected $description = 'Range la clé de traduction des anciens libellés du portefeuille dans metadata.label.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Mode simulation (--dry-run) : aucune écriture.');
        }

        $patterns = self::patterns();
        $matched = [];
        $unmatched = [];

        WalletTransaction::withTrashed()->orderBy('id')->chunkById(500, function ($transactions) use ($patterns, $dryRun, &$matched, &$unmatched) {
            foreach ($transactions as $transaction) {
                if (isset($transaction->metadata['label'])) {
                    continue;
                }

                $description = $transaction->getRawOriginal('description');
                $label = self::match((string) $description, $patterns);
                if ($label === null) {
                    $unmatched[$description] = ($unmatched[$description] ?? 0) + 1;
                    continue;
                }

                $matched[$label['key']] = ($matched[$label['key']] ?? 0) + 1;
                if (! $dryRun) {
                    // Sans événements ni date de mise à jour : seule la métadonnée change.
                    $transaction->timestamps = false;
                    $transaction->metadata = array_merge($transaction->metadata ?? [], ['label' => $label]);
                    $transaction->saveQuietly();
                }
            }
        });

        ksort($matched);
        $this->table(['Clé', 'Lignes'], collect($matched)->map(fn ($n, $key) => [$key, $n])->values()->all());
        $this->info(array_sum($matched).' ligne(s) '.($dryRun ? 'reconnue(s)' : 'reprise(s)').'.');

        if ($unmatched) {
            arsort($unmatched);
            $this->warn(array_sum($unmatched).' ligne(s) non reconnue(s), laissées telles quelles :');
            $this->table(['Libellé', 'Lignes'], collect($unmatched)->take(30)->map(fn ($n, $text) => [$text, $n])->values()->all());
        }

        return self::SUCCESS;
    }

    /**
     * Expression par modèle, les plus longs d'abord : « Vente commande #:order_number »
     * ne doit pas avaler « Vente commande #… — fonds débloqués ».
     *
     * @return array<string, array{regex: string, params: array<int, string>}>
     */
    public static function patterns(): array
    {
        $templates = (array) trans('wallet.transactions', [], ContentLocale::SOURCE);
        uasort($templates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $patterns = [];
        foreach ($templates as $code => $template) {
            preg_match_all('/:([a-z_]+)/', $template, $found);
            $regex = preg_quote($template, '/');
            $names = $found[1];
            usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($names as $param) {
                $regex = str_replace(preg_quote(":{$param}", '/'), "(?P<{$param}>.+?)", $regex);
            }
            $patterns["wallet.transactions.{$code}"] = ['regex' => "/^{$regex}$/u", 'params' => $found[1]];
        }

        return $patterns;
    }

    /** @return array{key: string, params: array<string, string>}|null */
    public static function match(string $description, array $patterns): ?array
    {
        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern['regex'], $description, $m)) {
                $params = [];
                foreach ($pattern['params'] as $param) {
                    $params[$param] = $m[$param];
                }

                return ['key' => $key, 'params' => $params];
            }
        }

        return null;
    }
}
