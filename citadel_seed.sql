USE citadel;

-- ============================================================
-- LECTURERS
-- ============================================================
INSERT IGNORE INTO users (full_name, email, password_hash, role) VALUES
('Asare Yaw Obeng',            'asare@citadel.edu',   '$2y$12$CHANGEME.lecturerHash1xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer'),
('Daniel Adjei',               'daniel@citadel.edu',  '$2y$12$CHANGEME.lecturerHash2xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer'),
('Mary Ann',                   'maryann@citadel.edu', '$2y$12$CHANGEME.lecturerHash3xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer'),
('Samuel King Opoku',          'samuel@citadel.edu',  '$2y$12$CHANGEME.lecturerHash4xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer'),
('Emmanuel Okyere Darko',      'emmanuel@citadel.edu','$2y$12$CHANGEME.lecturerHash5xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer'),
('Emily Opoku Aboagye-Dapaah', 'emily@citadel.edu',   '$2y$12$CHANGEME.lecturerHash6xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer'),
('Mercy',                      'mercy@citadel.edu',   '$2y$12$CHANGEME.lecturerHash7xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'lecturer');

-- ============================================================
-- COURSE REP: Ali Richard (index: 52430540017)
-- ============================================================
INSERT IGNORE INTO users (full_name, index_no, email, password_hash, role) VALUES
('Ali, Richard', '52430540017', '52430540017@citadel.edu', '$2y$12$CHANGEME.repHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'rep');

-- ============================================================
-- STUDENTS (70)
-- ============================================================
INSERT IGNORE INTO users (full_name, index_no, email, password_hash, role) VALUES
('Zackariah, Anas',              '52230540041', '52230540041@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Ibrahim, Okashata',            '52430540001', '52430540001@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Sarpong, Kumankuma',           '52430540003', '52430540003@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Ahmed, Hajara',                '52430540005', '52430540005@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Bukari, Michael',              '52430540006', '52430540006@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Owusu-Agyemang, Stephen',      '52430540008', '52430540008@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Agyei, Priscilla',             '52430540009', '52430540009@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Nimoh, Peter',                 '52430540010', '52430540010@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Baah, Gideon',                 '52430540012', '52430540012@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Boakye, Felix',                '52430540016', '52430540016@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Owusu, Dorcas',                '52430540018', '52430540018@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Essel, Moses',                 '52430540019', '52430540019@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Asante, Samuel',               '52430540021', '52430540021@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Sarfo, Evans',                 '52430540022', '52430540022@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Boakye, Janet',                '52430540023', '52430540023@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Awuah, Samuel',                '52430540024', '52430540024@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Norgan, Nadia',                '52430540026', '52430540026@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Bakara, Marinus',              '52430540027', '52430540027@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Quagraine, David',             '52430540028', '52430540028@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Addae, Kelvin',                '52430540029', '52430540029@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Owusu, Thelma',                '52430540030', '52430540030@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Frimpong, Emmanuel',           '52430540033', '52430540033@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Mbra, Samuel',                 '52430540035', '52430540035@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Alhassan, Shadrach',           '52430540036', '52430540036@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Yussif, Adinan',               '52430540038', '52430540038@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Antwi, Isaac',                 '52430540039', '52430540039@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Mohammed, Firdaus',            '52430540041', '52430540041@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Sarfo, Edward',                '52430540042', '52430540042@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Abonopaya, Clement',           '52430540043', '52430540043@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Issah, Abdul-Wahab',           '52430540044', '52430540044@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Haruna, Mohammed',             '52430540045', '52430540045@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Opoku, Joshua',                '52430540047', '52430540047@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Afubila, Gabriel',             '52430540048', '52430540048@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Awuah, Michael',               '52430540049', '52430540049@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Owusu, Gerrard',               '52430540051', '52430540051@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Musah, Desmond',               '52430540052', '52430540052@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Armah, Alex',                  '52430540053', '52430540053@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Boateng, Nurudeen',            '52430540054', '52430540054@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Issah, Misbaw',                '52430540055', '52430540055@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Tijani, Abideen',              '52430540056', '52430540056@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Anin, Sandra',                 '52430540057', '52430540057@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Mahamud, Abdul Gafaru',        '52430540059', '52430540059@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Asamoah, Richmond',            '52430540060', '52430540060@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Yeboah, Florence',             '52430540061', '52430540061@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Sawer, Ebenezer',              '52430540062', '52430540062@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Bashiru, Safiya',              '52430540063', '52430540063@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Tamatey, Raphael',             '52430540064', '52430540064@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Suallah, Buraida',             '52430540067', '52430540067@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Amoah, Isaac',                 '52430540068', '52430540068@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Gyapong, Afia',                '52430540069', '52430540069@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Adutwum, Gabriel',             '52430540071', '52430540071@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Amoah, Paul',                  '52430540072', '52430540072@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Etrew, James',                 '52430540076', '52430540076@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Dushie, Archippus',            '52430540077', '52430540077@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Harruna, Abdul Kudus',         '52430540078', '52430540078@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Amankwaah Asare, Ebenezer',    '52430540082', '52430540082@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Tawiah, Emmanuel',             '52430540083', '52430540083@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Amenebede, Wisdom',            '52430540084', '52430540084@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Kumah, Davis',                 '52430540085', '52430540085@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Boateng, Akowuah',             '52430540086', '52430540086@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Danquah, Joel',                '52430540088', '52430540088@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Avorgbedor, Felicity',         '52430540092', '52430540092@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Donkoh, Joseph',               '52430540093', '52430540093@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Ali, Hidayatu',                '52430540094', '52430540094@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Oppong Agyei, Justin',         '52430540095', '52430540095@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Sumaila, Saliu',               '52430540098', '52430540098@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Ackah, Mark Francis',          '52430540104', '52430540104@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Bansah, Donald',               '52430540106', '52430540106@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Kyei, Kwasi',                  '52430540107', '52430540107@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student'),
('Gyan, Hans',                   '52441360123', '52441360123@citadel.edu', '$2y$12$CHANGEME.studentHashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'student');

-- ============================================================
-- TIMETABLE
-- ============================================================
INSERT INTO timetable (course_code, course_name, day_of_week, start_time, end_time, room, lecturer_id) VALUES
('CSH221', 'Systems Analysis and Design',  'Monday',    '07:00:00', '09:00:00', 'CLT 303',  (SELECT id FROM users WHERE email='asare@citadel.edu')),
('CSH201', 'Human-Computer Interaction',   'Monday',    '11:00:00', '13:00:00', 'BTLT 401', (SELECT id FROM users WHERE email='daniel@citadel.edu')),
('CSH245', 'Probability and Statistics',   'Monday',    '13:00:00', '15:00:00', 'BTLT 402', (SELECT id FROM users WHERE email='maryann@citadel.edu')),
('CSH237', 'OOP with Java',                'Tuesday',   '07:00:00', '09:00:00', 'LAB 1',    (SELECT id FROM users WHERE email='samuel@citadel.edu')),
('CSH221', 'Systems Analysis and Design',  'Tuesday',   '09:00:00', '11:00:00', 'BLT 102',  (SELECT id FROM users WHERE email='asare@citadel.edu')),
('CSH261', 'Financial Accounting',         'Tuesday',   '11:00:00', '13:00:00', 'BTLT 404', (SELECT id FROM users WHERE email='emmanuel@citadel.edu')),
('CSH245', 'Probability and Statistics',   'Tuesday',   '13:00:00', '15:00:00', 'BTLT 401', (SELECT id FROM users WHERE email='maryann@citadel.edu')),
('CSH237', 'OOP with Java',                'Wednesday', '09:00:00', '11:00:00', 'CLT 303',  (SELECT id FROM users WHERE email='samuel@citadel.edu')),
('CSH231', 'Database Systems',             'Wednesday', '11:00:00', '13:00:00', 'MPLT 202', (SELECT id FROM users WHERE email='emily@citadel.edu')),
('CSH251', 'Web Development Technology',   'Wednesday', '13:00:00', '15:00:00', 'AFST 302', (SELECT id FROM users WHERE email='mercy@citadel.edu')),
('CSH251', 'Web Development Technology',   'Thursday',  '09:00:00', '11:00:00', 'BLT 102',  (SELECT id FROM users WHERE email='mercy@citadel.edu')),
('CSH201', 'Human-Computer Interaction',   'Thursday',  '11:00:00', '13:00:00', 'BT 102',   (SELECT id FROM users WHERE email='daniel@citadel.edu')),
('CSH231', 'Database Systems',             'Thursday',  '13:00:00', '15:00:00', 'BLT 102',  (SELECT id FROM users WHERE email='emily@citadel.edu'));
