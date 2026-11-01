<?php

return [
    // Only needed when an old individual session does not identify its subject.
    // Explicit enrollment ID => teacher_subject ID. Never infer among several subjects.
    'legacy_enrollment_subjects' => [],
    // Recovery only: verified enrollment ID => existing individual ID.
    // Leave empty unless an interrupted legacy insert requires an explicit mapping.
    'legacy_enrollment_individuals' => [],
];
