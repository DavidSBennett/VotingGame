-- 01_tables.sql — VotingGame initial schema.
--
-- Run manually in phpMyAdmin against the subdomain's dedicated database.
-- After running, hit admin_schemaCheck.php to confirm every table landed.
--
-- STATE MODEL: deliberately JSON-in-TEXT rather than normalized tables.
-- Every mutation runs single-writer inside a SELECT ... FOR UPDATE on the
-- vg_games row, so JSON blobs are safe, and — the real reason — the rules
-- can change every playtest without a migration. Columns exist only for
-- things we need to INDEX, SORT, or LOCK on. Everything else lives in
-- vg_games.state / vg_game_players.public_state / private_state.
--
-- Prefix vg_ on every table so this database stays legible if anything
-- else is ever installed alongside it.

-- ---------------------------------------------------------------------
-- vg_games — one row per playthrough.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vg_games (
  game_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Short human-typable code so a player can join from their phone
  -- without a link. Uppercase, ambiguity-free alphabet (see lib.php).
  join_code      VARCHAR(8) NOT NULL,

  status         ENUM('lobby','active','ended') NOT NULL DEFAULT 'lobby',

  -- Rules variant / edition key. Lets two rule sets coexist during a
  -- playtest series, and lets high scores be compared like-for-like.
  variant        VARCHAR(40) NOT NULL DEFAULT 'v1',

  max_players    TINYINT UNSIGNED NOT NULL DEFAULT 6,
  host_player_id INT UNSIGNED NULL,

  -- Turn/phase bookkeeping. Kept as columns (not inside state JSON)
  -- because the lobby list and admin screens sort and filter on them.
  phase          VARCHAR(40) NOT NULL DEFAULT 'lobby',
  round_number   INT UNSIGNED NOT NULL DEFAULT 0,
  current_seat   TINYINT UNSIGNED NULL,      -- NULL = simultaneous phase

  -- config: JSON, the knobs chosen at create time (round count, options).
  -- Frozen at start so a mid-series rule change never rewrites a game
  -- that is already in progress.
  config         TEXT NULL,

  -- state: JSON, the whole shared board. Engine-defined shape.
  state          MEDIUMTEXT NULL,

  ended_reason   VARCHAR(60) NULL,
  winner_seat    TINYINT UNSIGNED NULL,

  -- Polling: the client fetches full public state every 1.5s and only
  -- re-renders when this number changes.
  state_version  INT UNSIGNED NOT NULL DEFAULT 0,

  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  ended_at       TIMESTAMP NULL DEFAULT NULL,

  PRIMARY KEY (game_id),
  UNIQUE KEY uq_vg_games_join_code (join_code),
  KEY idx_vg_games_status (status),
  KEY idx_vg_games_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- vg_game_players — one row per seat. player_token is the ONLY auth:
-- standalone per-seat bearer tokens, no accounts, no shared login with
-- any other site. Issued at create/join, stored in localStorage.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vg_game_players (
  player_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id        INT UNSIGNED NOT NULL,
  seat           TINYINT UNSIGNED NOT NULL,
  player_name    VARCHAR(80) NOT NULL,
  player_token   VARCHAR(64) NOT NULL,

  is_bot         TINYINT(1) NOT NULL DEFAULT 0,

  -- public_state:  visible to every seat (published position, tableau…)
  -- private_state: visible ONLY to this seat (hand, hidden commitments).
  -- getState.php never serialises another seat's private_state.
  public_state   TEXT NULL,
  private_state  TEXT NULL,

  score           INT NOT NULL DEFAULT 0,   -- running/advisory score
  final_score     INT NULL,                 -- filled once at game end
  score_breakdown TEXT NULL,                -- JSON, per-category detail

  conceded       TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at   TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (player_id),
  UNIQUE KEY uq_vg_players_token (player_token),
  UNIQUE KEY uq_vg_players_seat (game_id, seat),
  KEY idx_vg_players_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- vg_event_log — EVERY action, from day one. This is the playtest
-- record: exports, the in-game feed, and all post-game analysis read
-- from here. Never prune it except through admin_clearFinished.php.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vg_event_log (
  event_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id      INT UNSIGNED NOT NULL,
  seat         TINYINT UNSIGNED NULL,       -- NULL = system/engine event
  player_name  VARCHAR(80) NULL,            -- denormalised so exports read
                                            -- correctly even after a rename
  round_number INT UNSIGNED NULL,
  phase        VARCHAR(40) NULL,
  event_type   VARCHAR(40) NOT NULL,
  message      VARCHAR(500) NULL,           -- pre-rendered player-facing text
  event_data   TEXT NULL,                   -- JSON detail for analysis
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (event_id),
  KEY idx_vg_events_game (game_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- vg_playtest_reports — notes + 1..5 rating filed from the results
-- screen or mid-game, stored with a snapshot of the game at filing time
-- so a complaint can always be read against the position that caused it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vg_playtest_reports (
  report_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id      INT UNSIGNED NULL,
  seat         TINYINT UNSIGNED NULL,
  player_name  VARCHAR(80) NULL,
  variant      VARCHAR(40) NULL,
  rating       TINYINT NULL,                -- 1..5, optional
  notes        TEXT NULL,
  snapshot     MEDIUMTEXT NULL,             -- JSON: round, phase, scores
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (report_id),
  KEY idx_vg_reports_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- vg_scores — the lobby high-score board. Written once per seat at game
-- end. Separate from vg_game_players so clearing finished games never
-- wipes the board.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vg_scores (
  score_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_id       INT UNSIGNED NOT NULL,
  seat          TINYINT UNSIGNED NOT NULL,
  player_name   VARCHAR(80) NOT NULL,
  variant       VARCHAR(40) NOT NULL DEFAULT 'v1',
  score         INT NOT NULL DEFAULT 0,
  players_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
  rounds        INT UNSIGNED NULL,
  ended_reason  VARCHAR(60) NULL,
  won           TINYINT(1) NOT NULL DEFAULT 0,
  detail        TEXT NULL,                  -- JSON score_breakdown copy
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (score_id),
  UNIQUE KEY uq_vg_scores_seat (game_id, seat),
  KEY idx_vg_scores_board (variant, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
