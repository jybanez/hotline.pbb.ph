<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClearTestIncidents extends Command
{
    protected $signature = 'app:clear-test-incidents
        {--operator-id= : Delete only incidents owned by this operator id}
        {--all : Delete all incidents}
        {--force : Required to perform deletion}';

    protected $description = 'Clear test incidents and their submitted incident-side data.';

    public function handle(): int
    {
        $operatorId = $this->option('operator-id');
        $deleteAll = (bool) $this->option('all');

        if (! $this->option('force')) {
            $this->error('Refusing to run without --force.');

            return self::FAILURE;
        }

        if ($deleteAll === ($operatorId !== null && $operatorId !== '')) {
            $this->error('Provide exactly one scope: --all or --operator-id=<id>.');

            return self::FAILURE;
        }

        $incidentQuery = DB::table('incidents');

        if (! $deleteAll) {
            $incidentQuery->where('operator_id', (int) $operatorId);
        }

        $incidentIds = $incidentQuery
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($incidentIds === []) {
            $this->info('No matching incidents found.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Deleting %d incident(s)%s.',
            count($incidentIds),
            $deleteAll ? ' across all operators' : " for operator_id={$operatorId}",
        ));

        $filePaths = $this->collectFilePaths($incidentIds);

        $summary = DB::transaction(function () use ($incidentIds): array {
            $summary = [];
            $callAttemptIds = $this->ids('call_attempts', 'incident_id', $incidentIds);
            $callSessionIds = $this->ids('call_sessions', 'incident_id', $incidentIds);
            $messageIds = $this->ids('incident_messages', 'incident_id', $incidentIds);
            $teamAssignmentIds = $this->ids('team_assignments', 'incident_id', $incidentIds);

            $summary['message_attachments'] = $this->deleteWhereIn('message_attachments', 'message_id', $messageIds);
            $summary['incident_messages'] = $this->deleteWhereIn('incident_messages', 'id', $messageIds);

            $summary['media'] = $this->deleteWhereIn('media', 'incident_id', $incidentIds);
            $summary['incident_citizen_locations'] = $this->deleteWhereIn('incident_citizen_locations', 'incident_id', $incidentIds);
            $summary['incident_caller_locations'] = $this->deleteWhereIn('incident_caller_locations', 'incident_id', $incidentIds);

            $summary['call_participants'] = $this->deleteWhereIn('call_participants', 'call_session_id', $callSessionIds);
            $summary['call_sessions'] = $this->deleteWhereIn('call_sessions', 'id', $callSessionIds);

            $summary['call_attempt_operator_attempts'] = $this->deleteWhereIn('call_attempt_operator_attempts', 'call_attempt_id', $callAttemptIds);
            $summary['call_attempts'] = $this->deleteWhereIn('call_attempts', 'id', $callAttemptIds);

            $summary['team_assignment_notes'] = $this->deleteWhereIn('team_assignment_notes', 'team_assignment_id', $teamAssignmentIds);
            $summary['team_assignment_allocated_resources'] = $this->deleteWhereIn('team_assignment_allocated_resources', 'team_assignment_id', $teamAssignmentIds);
            $summary['team_assignments'] = $this->deleteWhereIn('team_assignments', 'id', $teamAssignmentIds);

            $summary['incident_transfers'] = $this->deleteWhereIn('incident_transfers', 'incident_id', $incidentIds);
            $summary['activity_logs'] = $this->deleteWhereIn('activity_logs', 'incident_id', $incidentIds);
            $summary['incident_incident_type'] = $this->deleteWhereIn('incident_incident_type', 'incident_id', $incidentIds);
            $summary['incident_type_details'] = $this->deleteWhereIn('incident_type_details', 'incident_id', $incidentIds);
            $summary['incident_resources_needed'] = $this->deleteWhereIn('incident_resources_needed', 'incident_id', $incidentIds);
            $summary['incidents'] = $this->deleteWhereIn('incidents', 'id', $incidentIds);

            return $summary;
        });

        $deletedFiles = $this->deleteFiles($filePaths['public']);
        $deletedDirectories = $this->deleteDirectories($filePaths['local_directories']);

        foreach ($summary as $table => $count) {
            $this->line(sprintf('%s: %d', $table, $count));
        }

        $this->line(sprintf('public_files_deleted: %d', $deletedFiles));
        $this->line(sprintf('local_directories_deleted: %d', $deletedDirectories));
        $this->info('Test incident cleanup finished.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function ids(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteWhereIn(string $table, string $column, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->delete();
    }

    /**
     * @param  array<int, int>  $incidentIds
     * @return array{public: array<int, string>, local_directories: array<int, string>}
     */
    private function collectFilePaths(array $incidentIds): array
    {
        $publicPaths = [];

        $messageIds = $this->ids('incident_messages', 'incident_id', $incidentIds);
        DB::table('message_attachments')
            ->whereIn('message_id', $messageIds)
            ->select(['stored_path', 'thumbnail_path'])
            ->orderBy('id')
            ->get()
            ->each(function ($attachment) use (&$publicPaths): void {
                foreach (['stored_path', 'thumbnail_path'] as $column) {
                    $path = trim((string) ($attachment->{$column} ?? ''));

                    if ($path !== '') {
                        $publicPaths[] = $path;
                    }
                }
            });

        $localDirectories = [];
        DB::table('media')
            ->whereIn('incident_id', $incidentIds)
            ->select(['id', 'incident_id', 'call_session_id', 'path'])
            ->orderBy('id')
            ->get()
            ->each(function ($media) use (&$publicPaths, &$localDirectories): void {
                $path = trim((string) ($media->path ?? ''));

                if ($path !== '') {
                    $publicPaths[] = $path;
                }

                $localDirectories[] = sprintf(
                    'media-processing/%d/%d/%d',
                    (int) $media->incident_id,
                    (int) $media->call_session_id,
                    (int) $media->id,
                );
            });

        $incidentIds = array_values(array_unique(array_map('intval', $incidentIds)));

        foreach ($incidentIds as $incidentId) {
            $localDirectories[] = "media-processing/{$incidentId}";
        }

        return [
            'public' => array_values(array_unique($publicPaths)),
            'local_directories' => array_values(array_unique($localDirectories)),
        ];
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deleteFiles(array $paths): int
    {
        $disk = Storage::disk('public');
        $deleted = 0;

        foreach ($paths as $path) {
            $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');

            if ($normalized === '' || ! $disk->exists($normalized)) {
                continue;
            }

            if ($disk->delete($normalized)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deleteDirectories(array $paths): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;

        foreach ($paths as $path) {
            $normalized = trim(str_replace('\\', '/', $path), '/');

            if ($normalized === '' || ! $disk->exists($normalized)) {
                continue;
            }

            if ($disk->deleteDirectory($normalized)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
