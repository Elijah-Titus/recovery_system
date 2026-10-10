<?php
// recovery_system/includes/integrity.php
// Row fingerprints. row_hash() must produce EXACTLY what the live api_row_hash() produces.

// Must match API_TABLES on the live portal (same columns, same order).
const MIRROR_COLUMNS = [
    'exams'               => ['id', 'title', 'duration_minutes', 'is_active', 'created_by', 'created_at'],
    'questions'           => ['id', 'exam_id', 'position', 'question_text',
                              'option_a', 'option_b', 'option_c', 'option_d', 'correct_option'],
    'registered_students' => ['id', 'mat_number', 'full_name', 'created_at'],
    'student_results'     => ['id', 'exam_id', 'mat_number', 'score', 'total', 'submitted_at'],
];

// Every value cast to string, NULL as "\0NULL", joined with "\x1f", then SHA-256.
function row_hash(array $row, array $cols): string
{
    $parts = [];
    foreach ($cols as $c) {
        $parts[] = ($row[$c] ?? null) === null ? "\0NULL" : (string) $row[$c];
    }
    return hash('sha256', implode("\x1f", $parts));
}

// Fingerprint recomputed from what is actually stored in a backup row
function backup_row_hash(string $table, array $row): string
{
    return row_hash($row, MIRROR_COLUMNS[$table]);
}

// true = the stored data still matches the fingerprint taken when it was copied
function backup_row_is_intact(string $table, array $row): bool
{
    return hash_equals((string) $row['row_hash'], backup_row_hash($table, $row));
}