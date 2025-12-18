<?php

final class SchedulerApply
{
    public static function dryRun(SchedulerDiffResult $diff): void
    {
        foreach ($diff->create as $e) {
            GcsLog::info('[DRY-RUN] CREATE', ['uid' => $e->uid]);
        }

        foreach ($diff->update as $uid => $_) {
            GcsLog::info('[DRY-RUN] UPDATE', ['uid' => $uid]);
        }

        foreach ($diff->delete as $e) {
            GcsLog::info('[DRY-RUN] DELETE', ['uid' => $e->uid]);
        }
    }
}
