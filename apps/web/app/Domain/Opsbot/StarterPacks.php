<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Billing\PlanLimitExceeded;
use App\Domain\Opsbot\Access\Actor;
use App\Enums\ApprovalStatus;
use App\Enums\DocumentSource;
use App\Knowledge\DocumentService;
use App\Models\Department;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class StarterPacks
{
    public function __construct(
        private DocumentService $documents,
        private Auditor $auditor,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        $root = (string) config('opsbot.starter_packs_path');
        $keys = [];

        foreach (glob($root.'/*/pack.json') ?: [] as $file) {
            $keys[] = basename(dirname($file));
        }

        sort($keys);

        return $keys;
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $key): ?array
    {
        if (preg_match('/^[a-z0-9-]+$/', $key) !== 1) {
            return null;
        }

        $path = config('opsbot.starter_packs_path').'/'.$key.'/pack.json';

        if (! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(Workspace $workspace, string $locale): array
    {
        $packs = [];

        foreach ($this->keys() as $key) {
            $manifest = $this->manifest($key);

            if ($manifest === null) {
                continue;
            }

            $documents = (array) ($manifest['documents'] ?? []);

            $packs[] = [
                'key' => $key,
                'title' => self::localized($manifest['title'] ?? [], $locale),
                'description' => self::localized($manifest['description'] ?? [], $locale),
                'documents_count' => count($documents),
                'languages' => $this->languages($key),
                'departments' => array_values(array_map(
                    fn (mixed $department): array => [
                        'code' => (string) (is_array($department) ? ($department['code'] ?? '') : ''),
                        'name' => self::localized(is_array($department) ? ($department['name'] ?? []) : [], $locale),
                    ],
                    (array) ($manifest['departments'] ?? []),
                )),
                'imported' => Document::query()
                    ->forWorkspace($workspace)
                    ->where('source', DocumentSource::StarterPack->value)
                    ->where('source_ref', 'like', $key.':%')
                    ->where('approval_status', '!=', ApprovalStatus::Archived->value)
                    ->exists(),
            ];
        }

        return $packs;
    }

    /**
     * @param  list<string>  $languages
     * @return array{created: int, skipped: int, departments_created: int}
     *
     * @throws OpsbotException
     * @throws PlanLimitExceeded
     */
    public function import(Actor $actor, string $key, array $languages, bool $createDepartments): array
    {
        if (! $actor->can('knowledge.manage')) {
            throw OpsbotException::forbidden();
        }

        $manifest = $this->manifest($key);

        if ($manifest === null) {
            throw OpsbotException::notFound('starter_pack.not_found');
        }

        $languages = array_values(array_intersect($this->languages($key), $languages));

        if ($languages === []) {
            throw new OpsbotException('starter_pack.languages');
        }

        $workspace = $actor->workspace;
        $pending = [];

        foreach ((array) ($manifest['documents'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($languages as $language) {
                $ref = $key.':'.$entry['key'].':'.$language;
                $exists = Document::query()
                    ->forWorkspace($workspace)
                    ->where('source_ref', $ref)
                    ->where('approval_status', '!=', ApprovalStatus::Archived->value)
                    ->exists();

                if (! $exists) {
                    $pending[] = ['entry' => $entry, 'language' => $language, 'ref' => $ref];
                }
            }
        }

        $total = count((array) ($manifest['documents'] ?? [])) * count($languages);
        $bytes = 0;

        foreach ($pending as $item) {
            $bytes += strlen($this->content($key, $item['language'], (string) $item['entry']['file']));
        }

        if ($pending !== []) {
            $this->documents->ensureCanAdd($workspace, $bytes, count($pending));
        }

        return DB::transaction(function () use ($actor, $workspace, $manifest, $key, $pending, $createDepartments, $total, $languages): array {
            [$departments, $created] = $this->departments($actor, $workspace, (array) ($manifest['departments'] ?? []), $createDepartments);

            foreach ($pending as $item) {
                $entry = $item['entry'];
                $language = $item['language'];
                $content = $this->content($key, $language, (string) $entry['file']);
                $codes = array_map('strval', (array) ($entry['department_codes'] ?? []));
                $departmentIds = array_values(array_filter(array_map(fn (string $code): ?int => $departments[$code] ?? null, $codes)));

                $this->documents->createFromContent($workspace, $actor->user, $content, $entry['key'].'.'.$language.'.md', [
                    'title' => self::localized($entry['title'] ?? [], $language),
                    'department_ids' => $departmentIds,
                    'roles' => array_values(array_map('strval', (array) ($entry['roles'] ?? []))),
                    'tags' => array_values(array_map('strval', (array) ($entry['tags'] ?? []))),
                    'version' => isset($entry['version']) ? (string) $entry['version'] : null,
                    'language' => $language,
                    'source' => DocumentSource::StarterPack,
                    'source_ref' => $item['ref'],
                ], 'document.uploaded');
            }

            $this->auditor->record(
                'document.imported_starter_pack',
                $workspace,
                metadata: [
                    'pack' => $key,
                    'languages' => $languages,
                    'created' => count($pending),
                    'skipped' => $total - count($pending),
                    'departments_created' => $created,
                ],
                actor: $actor->user,
                workspace: $workspace,
                subjectLabel: $key,
            );

            return ['created' => count($pending), 'skipped' => $total - count($pending), 'departments_created' => $created];
        });
    }

    /** @return list<string> */
    public function languages(string $key): array
    {
        $languages = [];

        foreach (['ru', 'en'] as $language) {
            if (is_dir(config('opsbot.starter_packs_path').'/'.$key.'/'.$language)) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    public function content(string $key, string $language, string $file): string
    {
        $path = config('opsbot.starter_packs_path').'/'.$key.'/'.$language.'/'.basename($file);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @param  array<int, mixed>  $definitions
     * @return array{0: array<string, int>, 1: int}
     */
    private function departments(Actor $actor, Workspace $workspace, array $definitions, bool $create): array
    {
        $map = [];
        $created = 0;

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['code'] ?? null)) {
                continue;
            }

            $code = $definition['code'];
            $existing = Department::query()->forWorkspace($workspace)->where('code', $code)->first();

            if ($existing !== null) {
                $map[$code] = $existing->id;

                continue;
            }

            if (! $create) {
                continue;
            }

            $name = self::localized($definition['name'] ?? [], $workspace->locale);

            if (Department::query()->forWorkspace($workspace)->live()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                $name .= ' ('.$code.')';
            }

            $department = Department::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'code' => $code,
            ]);

            $this->auditor->record('department.created', $department, metadata: ['code' => $code], actor: $actor->user, workspace: $workspace, subjectLabel: $name);
            $map[$code] = $department->id;
            $created++;
        }

        return [$map, $created];
    }

    private static function localized(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        return (string) ($value[$locale] ?? $value['en'] ?? reset($value) ?: '');
    }
}
