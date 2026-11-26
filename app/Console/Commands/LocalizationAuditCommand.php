<?php

namespace App\Console\Commands;

use App\Services\System\LocalizationAuditService;
use Illuminate\Console\Command;

class LocalizationAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'localization:audit
        {--locale= : Audit a single locale code (e.g. vi, ar, ja)}
        {--module= : Filter frontend keys by top-level module prefix (e.g. admin, payment, auth)}
        {--format=table : Output format: table, json, or csv}
        {--no-source-scan : Skip scanning Vue/PHP/Blade source files for hardcoded text}
        {--strict : Exit with non-zero status if any missing, empty, duplicate, placeholder, or hardcoded issue exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit global 15-language localization coverage, placeholders, Laravel validation files, and hardcoded UI strings.';

    public function handle(LocalizationAuditService $auditService): int
    {
        $locale = is_string($this->option('locale')) ? trim($this->option('locale')) : null;
        $module = is_string($this->option('module')) ? trim($this->option('module')) : null;
        $format = is_string($this->option('format')) ? strtolower(trim($this->option('format'))) : 'table';
        $includeSource = ! (bool) $this->option('no-source-scan');
        $strict = (bool) $this->option('strict');

        $report = $auditService->audit(
            onlyLocale: $locale !== '' ? $locale : null,
            onlyModule: $module !== '' ? $module : null,
            includeSourceScan: $includeSource,
        );

        if ($format === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ($strict && ! $report['summary']['strict_passed']) ? self::FAILURE : self::SUCCESS;
        }

        if ($format === 'csv') {
            $this->line($auditService->toCsv($report));

            return ($strict && ! $report['summary']['strict_passed']) ? self::FAILURE : self::SUCCESS;
        }

        $this->info('SyncViet Global Localization Audit');
        $this->line(sprintf(
            'Default: %s | Fallback: %s | Frontend Keys: %d | Backend JSON Keys: %d | PHP Lang Keys: %d',
            $report['default_locale'],
            $report['fallback_locale'],
            $report['frontend_total_keys'],
            $report['backend_total_keys'],
            $report['php_lang_total_keys'],
        ));

        $rows = [];
        foreach ($report['locales'] as $row) {
            $rows[] = [
                $row['code'],
                $row['direction'],
                "{$row['frontend_translated']}/{$row['frontend_total']} ({$row['frontend_coverage_percent']}%)",
                "{$row['backend_translated']}/{$row['backend_total']} ({$row['backend_coverage_percent']}%)",
                "{$row['php_lang_translated']}/{$row['php_lang_total']} ({$row['php_lang_coverage_percent']}%)",
                count($row['frontend_missing']) + count($row['backend_missing']) + count($row['php_lang_missing']),
                count($row['frontend_empty']) + count($row['backend_empty']),
                count($row['frontend_placeholder_errors']) + count($row['backend_placeholder_errors']) + count($row['php_lang_placeholder_errors']),
                "{$row['overall_coverage_percent']}%",
                $row['review_status'],
            ];
        }

        $this->table(
            ['Locale', 'Dir', 'Frontend', 'Backend JSON', 'PHP Lang', 'Missing', 'Empty', 'Ph Err', 'Overall', 'Status'],
            $rows,
        );

        if ($includeSource) {
            $scan = $report['source_scan'];
            $this->newLine();
            $this->info('Source Scan Summary:');
            $this->line('  Undefined frontend t() keys : '.count($scan['undefined_frontend_keys']));
            $this->line('  Missing backend __() keys   : '.count($scan['missing_backend_json_keys']));
            $this->line('  2-locale vi shortcuts       : '.count($scan['vi_locale_shortcuts']));
            $this->line('  Hardcoded Vue UI strings    : '.$scan['hardcoded_vue_total'].' across '.count($scan['hardcoded_vue_files']).' files');
            $this->line('  Hardcoded PHP abort msgs    : '.count($scan['hardcoded_php_messages']));
            $this->line('  Unused frontend keys (info) : '.$scan['unused_frontend_keys_count']);
        }

        if ($strict && ! $report['summary']['strict_passed']) {
            $this->error('Strict localization audit FAILED.');

            return self::FAILURE;
        }

        $this->info($report['summary']['strict_passed'] ? 'Localization audit PASSED cleanly.' : 'Audit completed with issues to resolve.');

        return self::SUCCESS;
    }
}
