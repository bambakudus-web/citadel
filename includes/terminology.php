<?php
// includes/terminology.php
// Returns institution-type-aware terminology

function terms(string $key, ?string $instType = null): string {
    $type = $instType ?? ($_SESSION['inst_type'] ?? 'university');
    
    $map = [
        'university' => [
            'lecturer'   => 'Lecturer',
            'lecturers'  => 'Lecturers',
            'course'     => 'Course',
            'courses'    => 'Courses',
            'rep'        => 'Course Rep',
            'reps'       => 'Course Reps',
            'index_no'   => 'Index Number',
            'semester'   => 'Semester',
            'semesters'  => 'Semesters',
            'session'    => 'Session',
            'program'    => 'Program',
            'programs'   => 'Programs',
            'department' => 'Department',
            'faculty'    => 'Faculty',
        ],
        'shs' => [
            'lecturer'   => 'Teacher',
            'lecturers'  => 'Teachers',
            'course'     => 'Subject',
            'courses'    => 'Subjects',
            'rep'        => 'Class Prefect',
            'reps'       => 'Class Prefects',
            'index_no'   => 'Student ID',
            'semester'   => 'Term',
            'semesters'  => 'Terms',
            'session'    => 'Class',
            'program'    => 'Stream',
            'programs'   => 'Streams',
            'department' => 'Department',
            'faculty'    => 'House',
        ],
        'jhs' => [
            'lecturer'   => 'Teacher',
            'lecturers'  => 'Teachers',
            'course'     => 'Subject',
            'courses'    => 'Subjects',
            'rep'        => 'Class Prefect',
            'reps'       => 'Class Prefects',
            'index_no'   => 'Student ID',
            'semester'   => 'Term',
            'semesters'  => 'Terms',
            'session'    => 'Class',
            'program'    => 'Class',
            'programs'   => 'Classes',
            'department' => 'Department',
            'faculty'    => 'House',
        ],
        'primary' => [
            'lecturer'   => 'Teacher',
            'lecturers'  => 'Teachers',
            'course'     => 'Subject',
            'courses'    => 'Subjects',
            'rep'        => 'Class Captain',
            'reps'       => 'Class Captains',
            'index_no'   => 'Student ID',
            'semester'   => 'Term',
            'semesters'  => 'Terms',
            'session'    => 'Class',
            'program'    => 'Class',
            'programs'   => 'Classes',
            'department' => 'Department',
            'faculty'    => 'House',
        ],
    ];

    return $map[$type][$key] ?? $map['university'][$key] ?? $key;
}
