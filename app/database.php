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
    SQL);
    return $db;
}
