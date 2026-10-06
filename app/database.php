<?php
declare(strict_types=1);

function database(string $storage): PDO
{
    $db = new PDO('sqlite:' . $storage . '/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'guardian' CHECK (role IN ('guardian')),
            auth_version INTEGER NOT NULL DEFAULT 1,
            privacy_acknowledged_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL,
            used_at INTEGER,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS reset_user_idx ON password_resets(user_id);
        CREATE TABLE IF NOT EXISTS rate_limits (
            bucket TEXT PRIMARY KEY,
            attempts INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS audit_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS admin_accounts (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL CHECK (role = 'central_admin'),
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS staff_accounts (
            user_id INTEGER PRIMARY KEY REFERENCES users(id),
            role TEXT NOT NULL CHECK (role IN ('school_admin', 'verifier')),
            enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
            can_approve INTEGER NOT NULL DEFAULT 0 CHECK (can_approve IN (0, 1)),
            version INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS account_security (
            user_id INTEGER PRIMARY KEY REFERENCES users(id),
            email_verified_at INTEGER,
            mfa_secret TEXT,
            last_counter INTEGER NOT NULL DEFAULT -1
        );
        CREATE TABLE IF NOT EXISTS email_verifications (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id),
            expires_at INTEGER NOT NULL,
            used_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
            user_id INTEGER NOT NULL REFERENCES users(id),
            code_hash TEXT NOT NULL,
            used_at INTEGER,
            PRIMARY KEY (user_id, code_hash)
        );
    SQL);
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS admission_periods (
            id TEXT PRIMARY KEY,
            code TEXT NOT NULL UNIQUE,
            organizer TEXT NOT NULL,
            school TEXT NOT NULL,
            level TEXT NOT NULL CHECK (level IN ('SD', 'SMP', 'SMA')),
            academic_year TEXT NOT NULL,
            opens_at INTEGER NOT NULL,
            closes_at INTEGER NOT NULL CHECK (closes_at > opens_at),
            timezone TEXT NOT NULL,
            is_demo INTEGER NOT NULL CHECK (is_demo IN (0, 1)),
            config_json TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS participant_profiles (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id),
            data_json TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS admission_period_availability (
            period_id TEXT PRIMARY KEY REFERENCES admission_periods(id),
            enabled INTEGER NOT NULL CHECK (enabled IN (0, 1))
        );
        CREATE INDEX IF NOT EXISTS participant_owner_idx ON participant_profiles(user_id);
        CREATE TABLE IF NOT EXISTS applications (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id),
            profile_id TEXT NOT NULL REFERENCES participant_profiles(id),
            period_id TEXT NOT NULL REFERENCES admission_periods(id),
            pathway TEXT NOT NULL,
            data_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted')),
            version INTEGER NOT NULL DEFAULT 1,
            registration_number TEXT UNIQUE,
            rule_snapshot_json TEXT,
            declaration_at INTEGER,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            submitted_at INTEGER,
            UNIQUE(profile_id, period_id)
        );
        CREATE INDEX IF NOT EXISTS application_owner_idx ON applications(user_id);
        CREATE TABLE IF NOT EXISTS application_documents (
            id TEXT PRIMARY KEY,
            application_id TEXT NOT NULL REFERENCES applications(id),
            kind TEXT NOT NULL,
            original_name TEXT NOT NULL,
            storage_name TEXT NOT NULL UNIQUE,
            mime_type TEXT NOT NULL,
            size INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            uploaded_at INTEGER NOT NULL,
            deleted_at INTEGER
        );
        CREATE UNIQUE INDEX IF NOT EXISTS active_document_idx
            ON application_documents(application_id, kind) WHERE deleted_at IS NULL;
        CREATE TABLE IF NOT EXISTS application_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            application_id TEXT NOT NULL REFERENCES applications(id),
            actor_id INTEGER NOT NULL REFERENCES users(id),
            action TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS document_deletion_queue (
            storage_name TEXT PRIMARY KEY,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS application_verifications (
            application_id TEXT PRIMARY KEY REFERENCES applications(id),
            status TEXT NOT NULL CHECK (status IN ('valid', 'needs_correction', 'invalid')),
            note TEXT NOT NULL,
            reviewer_id INTEGER NOT NULL REFERENCES users(id),
            version INTEGER NOT NULL DEFAULT 1,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS verification_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            application_id TEXT NOT NULL REFERENCES applications(id),
            status TEXT NOT NULL CHECK (status IN ('valid', 'needs_correction', 'invalid')),
            note TEXT NOT NULL,
            reviewer_id INTEGER NOT NULL REFERENCES users(id),
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS master_schools (
            id TEXT PRIMARY KEY,
            npsn TEXT UNIQUE,
            name TEXT NOT NULL,
            level TEXT NOT NULL CHECK (level IN ('SD', 'SMP', 'SMA')),
            mode TEXT NOT NULL CHECK (mode IN ('public_spmb', 'private_independent')),
            province TEXT NOT NULL,
            city TEXT NOT NULL,
            district TEXT NOT NULL,
            address TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
            version INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS period_school_links (
            period_id TEXT PRIMARY KEY REFERENCES admission_periods(id),
            school_id TEXT NOT NULL REFERENCES master_schools(id)
        );
        CREATE TABLE IF NOT EXISTS staff_schools (
            user_id INTEGER NOT NULL REFERENCES staff_accounts(user_id),
            school_id TEXT NOT NULL REFERENCES master_schools(id) ON DELETE CASCADE,
            PRIMARY KEY (user_id, school_id)
        );
        CREATE TABLE IF NOT EXISTS staff_invitations (
            id TEXT PRIMARY KEY,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('school_admin', 'verifier')),
            can_approve INTEGER NOT NULL CHECK (can_approve IN (0, 1)),
            token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL,
            used_at INTEGER,
            cancelled_at INTEGER,
            created_by INTEGER NOT NULL REFERENCES users(id),
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS invitation_schools (
            invitation_id TEXT NOT NULL REFERENCES staff_invitations(id),
            school_id TEXT NOT NULL REFERENCES master_schools(id) ON DELETE CASCADE,
            PRIMARY KEY (invitation_id, school_id)
        );
        CREATE TABLE IF NOT EXISTS verification_assignments (
            application_id TEXT PRIMARY KEY REFERENCES applications(id),
            reviewer_id INTEGER REFERENCES staff_accounts(user_id),
            version INTEGER NOT NULL DEFAULT 1,
            assigned_by INTEGER NOT NULL REFERENCES users(id),
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS period_management (
            period_id TEXT PRIMARY KEY REFERENCES admission_periods(id),
            version INTEGER NOT NULL DEFAULT 1,
            used INTEGER NOT NULL DEFAULT 0 CHECK (used IN (0, 1))
        );
        CREATE TRIGGER IF NOT EXISTS remember_period_usage AFTER INSERT ON applications
        BEGIN
            INSERT INTO period_management(period_id, used) VALUES (NEW.period_id, 1)
            ON CONFLICT(period_id) DO UPDATE SET used = 1;
        END;
        INSERT INTO period_management(period_id, used) SELECT DISTINCT period_id, 1 FROM applications WHERE 1
        ON CONFLICT(period_id) DO UPDATE SET used = 1;
    SQL);
    syncMasterSchools($db);
    return $db;
}

function syncMasterSchools(PDO $db): void
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $periods = $db->query('SELECT p.* FROM admission_periods p LEFT JOIN period_school_links l ON l.period_id = p.id
            WHERE l.period_id IS NULL')->fetchAll();
        foreach ($periods as $period) {
            $rules = json_decode($period['config_json'], true, 32, JSON_THROW_ON_ERROR);
            $npsn = ($rules['npsn'] ?? '') !== '' ? $rules['npsn'] : null;
            $id = bin2hex(random_bytes(16));
            if ($npsn) {
                $existing = $db->prepare('SELECT id FROM master_schools WHERE npsn = ?');
                $existing->execute([$npsn]);
                $id = $existing->fetchColumn() ?: $id;
            } else {
                $existing = $db->prepare('SELECT id FROM master_schools WHERE npsn IS NULL AND name=? AND level=?');
                $existing->execute([$period['school'], $period['level']]);
                $id = $existing->fetchColumn() ?: $id;
            }
            $db->prepare('INSERT INTO master_schools(id, npsn, name, level, mode, province, city, district, address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(id) DO NOTHING')
                ->execute([$id, $npsn, $period['school'], $period['level'], $rules['admission_mode'] ?? 'public_spmb',
                    $rules['province'] ?? '', $rules['regency'] ?? '', $rules['district'] ?? '', '']);
            $db->prepare('INSERT INTO period_school_links(period_id, school_id) VALUES (?, ?)')->execute([$period['id'], $id]);
            $db->prepare('INSERT INTO period_management(period_id) VALUES (?) ON CONFLICT DO NOTHING')->execute([$period['id']]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}
