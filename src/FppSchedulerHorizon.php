<?php

class GcsFppSchedulerHorizon
{
    public static function getDays(): int
    {
        // Baseline behavior: default horizon
        // (Actual implementation may read FPP settings in your baseline)
        return 30;
    }
}

