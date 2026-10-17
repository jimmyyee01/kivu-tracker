-- =====================================================================
--  KIVU Tracker — GPS Tracking & Fleet Management Platform
--  MySQL / MariaDB schema (utf8mb4, InnoDB)
--  1) Import this file:  mysql -u root kivu_tracker < database.sql
--  2) Edit php/config.php (DB credentials) if needed.
--  3) Open install.php once in the browser to create the demo data.
--  Every CREATE below is IF NOT EXISTS, so re-importing is always safe and
--  install.php can also create any missing table on its own.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- accounts
CREATE TABLE IF NOT EXISTS accounts (
  id            VARCHAR(32)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(80)  NOT NULL,
  plan          VARCHAR(40)  NOT NULL DEFAULT 'standard',
  plan_id       VARCHAR(32)  DEFAULT NULL,                      -- row in `plans`
  status        VARCHAR(20)  NOT NULL DEFAULT 'trialing',        -- trialing|active|past_due|expired|suspended|comp
  trial_ends_at DATETIME     DEFAULT NULL,                       -- end of the free trial (default 5 days)
  units_limit   INT          NOT NULL DEFAULT 50,
  storage_days  INT          NOT NULL DEFAULT 90,
  currency      VARCHAR(8)   NOT NULL DEFAULT 'USD',
  timezone      VARCHAR(64)  NOT NULL DEFAULT 'Africa/Nairobi',
  contact_email VARCHAR(160) DEFAULT NULL,
  contact_name  VARCHAR(120) DEFAULT NULL,
  contact_phone VARCHAR(40)  DEFAULT NULL,
  billing_address VARCHAR(255) DEFAULT NULL,         -- invoicing details (post-signup step)
  billing_city    VARCHAR(120) DEFAULT NULL,
  billing_country VARCHAR(64)  DEFAULT NULL,
  auto_renew      TINYINT      NOT NULL DEFAULT 1,   -- charge the saved method at renewal
  default_asset_type VARCHAR(32) DEFAULT NULL,        -- captured at registration (lorry, motorcycle, …)
  billing_email VARCHAR(160) DEFAULT NULL,
  signup_ip     VARCHAR(64)  DEFAULT NULL,
  signup_source VARCHAR(20)  DEFAULT NULL,                       -- self|admin
  notes         VARCHAR(255) DEFAULT NULL,
  enabled       TINYINT      NOT NULL DEFAULT 1,
  created_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS users (
  id            VARCHAR(32)  NOT NULL,
  account_id    VARCHAR(32)  DEFAULT NULL,       -- NULL = platform super admin
  username      VARCHAR(80)  NOT NULL,
  email         VARCHAR(160) DEFAULT NULL,
  phone         VARCHAR(40)  DEFAULT NULL,
  full_name     VARCHAR(120) DEFAULT NULL,
  role          VARCHAR(20)  NOT NULL DEFAULT 'operator', -- super|admin|operator|viewer
  salt          VARCHAR(64)  DEFAULT NULL,
  password_hash VARCHAR(255) DEFAULT NULL,
  access_units_json  LONGTEXT DEFAULT NULL,      -- NULL = full account access
  access_mask_json   LONGTEXT DEFAULT NULL,      -- feature switches
  enabled       TINYINT      NOT NULL DEFAULT 1,
  last_login    DATETIME     DEFAULT NULL,
  last_ip       VARCHAR(64)  DEFAULT NULL,
  created_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- sessions
CREATE TABLE IF NOT EXISTS sessions (
  token      VARCHAR(64) NOT NULL,
  user_id    VARCHAR(32) NOT NULL,
  created_at DATETIME    DEFAULT NULL,
  expires_at DATETIME    DEFAULT NULL,
  ip         VARCHAR(64) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (token),
  KEY ix_sessions_user (user_id),
  KEY ix_sessions_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- unit_groups
CREATE TABLE IF NOT EXISTS unit_groups (
  id         VARCHAR(32)  NOT NULL,
  account_id VARCHAR(32)  NOT NULL,
  parent_id  VARCHAR(32)  DEFAULT NULL,
  name       VARCHAR(120) NOT NULL,
  color      VARCHAR(16)  DEFAULT NULL,
  created_at DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_groups_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------- units
CREATE TABLE IF NOT EXISTS units (
  id            VARCHAR(32)  NOT NULL,
  account_id    VARCHAR(32)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  unique_id     VARCHAR(64)  NOT NULL,   -- device IMEI / IPS login name
  hw_type       VARCHAR(60)  NOT NULL DEFAULT 'ips',
  device_password VARCHAR(60) NOT NULL DEFAULT 'track',
  phone         VARCHAR(40)  DEFAULT NULL,
  model         VARCHAR(80)  DEFAULT NULL,
  plate         VARCHAR(40)  DEFAULT NULL,
  vin           VARCHAR(40)  DEFAULT NULL,
  trailer       VARCHAR(60)  DEFAULT NULL,
  group_id      VARCHAR(32)  DEFAULT NULL,
  tags          VARCHAR(255) DEFAULT NULL,
  driver_id     VARCHAR(32)  DEFAULT NULL,
  asset_type    VARCHAR(32)  DEFAULT NULL,            -- lorry | motorcycle | bicycle | car | …
  fuel_rate     DECIMAL(6,2) NOT NULL DEFAULT 8.00,   -- L / 100 km
  fuel_price    DECIMAL(8,3) NOT NULL DEFAULT 1.200,
  cost_per_km   DECIMAL(8,3) NOT NULL DEFAULT 0.350,
  odometer_km   DECIMAL(12,2) NOT NULL DEFAULT 0,
  engine_hours  DECIMAL(12,2) NOT NULL DEFAULT 0,
  speed_limit   INT          NOT NULL DEFAULT 90,
  parking_timeout_s INT      NOT NULL DEFAULT 180,
  min_trip_s    INT          NOT NULL DEFAULT 60,
  min_trip_km   DECIMAL(6,2) NOT NULL DEFAULT 0.30,
  min_park_s    INT          NOT NULL DEFAULT 300,
  max_jump_km   DECIMAL(6,2) NOT NULL DEFAULT 3.00,
  custom_fields_json LONGTEXT DEFAULT NULL,
  enabled       TINYINT      NOT NULL DEFAULT 1,
  last_time     DATETIME     DEFAULT NULL,
  last_lat      DECIMAL(10,7) DEFAULT NULL,
  last_lon      DECIMAL(10,7) DEFAULT NULL,
  last_speed    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  last_course   DECIMAL(6,2)  NOT NULL DEFAULT 0,
  last_alt      DECIMAL(8,2)  DEFAULT NULL,
  last_sats     INT          DEFAULT NULL,
  last_ignition TINYINT      DEFAULT NULL,
  last_fuel     DECIMAL(8,2) DEFAULT NULL,
  last_voltage  DECIMAL(6,2) DEFAULT NULL,
  last_mileage  DECIMAL(12,2) DEFAULT NULL,
  moving        TINYINT      NOT NULL DEFAULT 0,
  online        TINYINT      NOT NULL DEFAULT 0,
  last_ip       VARCHAR(64)  DEFAULT NULL,
  created_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_unique (unique_id),
  KEY ix_units_account (account_id),
  KEY ix_units_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- messages
CREATE TABLE IF NOT EXISTS messages (
  id        BIGINT      NOT NULL AUTO_INCREMENT,
  unit_id   VARCHAR(32) NOT NULL,
  ts        DATETIME    NOT NULL,
  lat       DECIMAL(10,7) DEFAULT NULL,
  lon       DECIMAL(10,7) DEFAULT NULL,
  speed     DECIMAL(6,2)  NOT NULL DEFAULT 0,
  course    DECIMAL(6,2)  NOT NULL DEFAULT 0,
  alt       DECIMAL(8,2)  NOT NULL DEFAULT 0,
  sats      INT           NOT NULL DEFAULT 0,
  hdop      DECIMAL(5,2)  DEFAULT NULL,
  ignition  TINYINT       DEFAULT NULL,
  fuel      DECIMAL(8,2)  DEFAULT NULL,
  voltage   DECIMAL(6,2)  DEFAULT NULL,
  mileage   DECIMAL(12,2) DEFAULT NULL,
  engine_hours DECIMAL(12,2) DEFAULT NULL,
  params_json LONGTEXT    DEFAULT NULL,
  valid     TINYINT       NOT NULL DEFAULT 1,   -- 0 = filtered GPS jump / no fix
  source    VARCHAR(20)   NOT NULL DEFAULT 'ips',
  created_at DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_msg_unit_ts (unit_id, ts),
  KEY ix_msg_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------- sensors
CREATE TABLE IF NOT EXISTS sensors (
  id          VARCHAR(32)  NOT NULL,
  unit_id     VARCHAR(32)  NOT NULL,
  account_id  VARCHAR(32)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  type        VARCHAR(30)  NOT NULL DEFAULT 'custom', -- fuel_level|ignition|temperature|voltage|counter|custom
  param       VARCHAR(40)  NOT NULL,                  -- IPS parameter name (fuel, ign, pwr, temp, adc1 ...)
  units       VARCHAR(20)  DEFAULT NULL,
  coefficient DECIMAL(10,4) NOT NULL DEFAULT 1,
  offset_val  DECIMAL(10,4) NOT NULL DEFAULT 0,
  min_val     DECIMAL(12,3) DEFAULT NULL,
  max_val     DECIMAL(12,3) DEFAULT NULL,
  color       VARCHAR(16)  DEFAULT NULL,
  enabled     TINYINT      NOT NULL DEFAULT 1,
  created_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_sensors_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------- trips
-- One row per detected trip / parking / idle block.
CREATE TABLE IF NOT EXISTS trips (
  id            VARCHAR(32)  NOT NULL,
  unit_id       VARCHAR(32)  NOT NULL,
  account_id    VARCHAR(32)  NOT NULL,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'trip',  -- trip|parking|idle
  start_ts      DATETIME     NOT NULL,
  end_ts        DATETIME     NOT NULL,
  start_lat     DECIMAL(10,7) DEFAULT NULL,
  start_lon     DECIMAL(10,7) DEFAULT NULL,
  end_lat       DECIMAL(10,7) DEFAULT NULL,
  end_lon       DECIMAL(10,7) DEFAULT NULL,
  start_address VARCHAR(200) DEFAULT NULL,
  end_address   VARCHAR(200) DEFAULT NULL,
  distance_km   DECIMAL(10,3) NOT NULL DEFAULT 0,
  max_speed     DECIMAL(6,2)  NOT NULL DEFAULT 0,
  avg_speed     DECIMAL(6,2)  NOT NULL DEFAULT 0,
  fuel_used     DECIMAL(8,3)  NOT NULL DEFAULT 0,
  duration_s    INT           NOT NULL DEFAULT 0,
  moving_s      INT           NOT NULL DEFAULT 0,
  idle_s        INT           NOT NULL DEFAULT 0,
  stops         INT           NOT NULL DEFAULT 0,
  start_mileage DECIMAL(12,2) DEFAULT NULL,
  end_mileage   DECIMAL(12,2) DEFAULT NULL,
  driver        VARCHAR(120) DEFAULT NULL,
  notes         VARCHAR(255) DEFAULT NULL,
  created_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_trips_unit_ts (unit_id, start_ts),
  KEY ix_trips_account (account_id, start_ts),
  KEY ix_trips_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------- geofences
CREATE TABLE IF NOT EXISTS geofences (
  id          VARCHAR(32)  NOT NULL,
  account_id  VARCHAR(32)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  type        VARCHAR(16)  NOT NULL DEFAULT 'polygon', -- circle|polygon|polyline
  points_json LONGTEXT     DEFAULT NULL,   -- [[lat,lon],[lat,lon]...]; circle = single point
  radius_m    DECIMAL(10,2) DEFAULT NULL,  -- circle only
  color       VARCHAR(16)  NOT NULL DEFAULT '#2f9cf4',
  width       INT          NOT NULL DEFAULT 2,
  group_id    VARCHAR(32)  DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  speed_limit INT          DEFAULT NULL,   -- geofence-level speed limit (m/s -> km/h in UI)
  enabled     TINYINT      NOT NULL DEFAULT 1,
  created_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_geo_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS geofence_events (
  id            BIGINT      NOT NULL AUTO_INCREMENT,
  account_id    VARCHAR(32) NOT NULL,
  geofence_id   VARCHAR(32) NOT NULL,
  geofence_name VARCHAR(120) DEFAULT NULL,
  unit_id       VARCHAR(32) NOT NULL,
  event         VARCHAR(8)  NOT NULL,     -- enter|exit
  ts            DATETIME    NOT NULL,
  lat           DECIMAL(10,7) DEFAULT NULL,
  lon           DECIMAL(10,7) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_gfe_unit_ts (unit_id, ts),
  KEY ix_gfe_geo_ts (geofence_id, ts),
  KEY ix_gfe_account (account_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------- notifications
CREATE TABLE IF NOT EXISTS notifications (
  id             VARCHAR(32)  NOT NULL,
  account_id     VARCHAR(32)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  type           VARCHAR(30)  NOT NULL, -- speed|geofence|ignition|sos|fuel_drop|fuel_fill|idle|power_cut|offline|movement|maintenance
  unit_ids_json      LONGTEXT  DEFAULT NULL,  -- NULL / [] = all units
  geofence_ids_json  LONGTEXT  DEFAULT NULL,
  params_json        LONGTEXT  DEFAULT NULL,  -- {"speed_limit":90,"min_change":20,"idle_min":20,...}
  text_template      VARCHAR(500) DEFAULT NULL,
  actions_json       LONGTEXT  DEFAULT NULL,  -- ["push","webhook","email","sms"]
  webhook_url    VARCHAR(255) DEFAULT NULL,
  email_to       VARCHAR(255) DEFAULT NULL,
  phone_to       VARCHAR(60)  DEFAULT NULL,
  severity       VARCHAR(12)  NOT NULL DEFAULT 'warning', -- info|warning|critical
  enabled        TINYINT      NOT NULL DEFAULT 1,
  last_fired     DATETIME     DEFAULT NULL,
  fired_count    INT          NOT NULL DEFAULT 0,
  created_at     DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_notif_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------ events
CREATE TABLE IF NOT EXISTS events (
  id              BIGINT      NOT NULL AUTO_INCREMENT,
  account_id      VARCHAR(32) NOT NULL,
  unit_id         VARCHAR(32) DEFAULT NULL,
  notification_id VARCHAR(32) DEFAULT NULL,
  geofence_id     VARCHAR(32) DEFAULT NULL,
  ts              DATETIME    NOT NULL,
  type            VARCHAR(40) NOT NULL,
  text            VARCHAR(500) DEFAULT NULL,
  lat             DECIMAL(10,7) DEFAULT NULL,
  lon             DECIMAL(10,7) DEFAULT NULL,
  speed           DECIMAL(6,2)  DEFAULT NULL,
  severity        VARCHAR(12) NOT NULL DEFAULT 'info',
  acknowledged    TINYINT     NOT NULL DEFAULT 0,
  extra_json      LONGTEXT    DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_events_unit_ts (unit_id, ts),
  KEY ix_events_account (account_id, ts),
  KEY ix_events_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- commands
CREATE TABLE IF NOT EXISTS commands (
  id           VARCHAR(32)  NOT NULL,
  account_id   VARCHAR(32)  NOT NULL,
  unit_id      VARCHAR(32)  NOT NULL,
  type         VARCHAR(40)  NOT NULL,   -- custom_message|engine_block|engine_unblock|reboot|query_position|set_interval
  payload_json LONGTEXT     DEFAULT NULL,
  state        VARCHAR(16)  NOT NULL DEFAULT 'pending', -- pending|sent|acked|failed
  created_by   VARCHAR(32)  DEFAULT NULL,
  created_at   DATETIME     DEFAULT NULL,
  sent_at      DATETIME     DEFAULT NULL,
  acked_at     DATETIME     DEFAULT NULL,
  result       VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_cmd_unit_state (unit_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------- drivers
CREATE TABLE IF NOT EXISTS drivers (
  id          VARCHAR(32)  NOT NULL,
  account_id  VARCHAR(32)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  phone       VARCHAR(40)  DEFAULT NULL,
  licence     VARCHAR(60)  DEFAULT NULL,
  id_number   VARCHAR(60)  DEFAULT NULL,
  address     VARCHAR(200) DEFAULT NULL,
  notes       VARCHAR(255) DEFAULT NULL,
  created_at  DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_drivers_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------- maintenance items
CREATE TABLE IF NOT EXISTS maintenance (
  id                VARCHAR(32)  NOT NULL,
  account_id        VARCHAR(32)  NOT NULL,
  unit_id           VARCHAR(32)  NOT NULL,
  name              VARCHAR(120) NOT NULL,
  interval_km       DECIMAL(10,2) DEFAULT NULL,
  interval_hours    DECIMAL(10,2) DEFAULT NULL,
  interval_days     INT           DEFAULT NULL,
  last_service_km   DECIMAL(12,2) DEFAULT NULL,
  last_service_hours DECIMAL(12,2) DEFAULT NULL,
  last_service_date DATE          DEFAULT NULL,
  cost              DECIMAL(10,2) DEFAULT NULL,
  notes             VARCHAR(255) DEFAULT NULL,
  created_at        DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_maint_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------- report templates
CREATE TABLE IF NOT EXISTS report_templates (
  id         VARCHAR(32)  NOT NULL,
  account_id VARCHAR(32)  NOT NULL,
  name       VARCHAR(120) NOT NULL,
  type       VARCHAR(40)  NOT NULL,   -- unit_summary|trips|parkings|geofences|events|speed_violations|fuel|activity|engine_hours|drivers_by_unit|mileage
  params_json LONGTEXT    DEFAULT NULL,
  created_by VARCHAR(32)  DEFAULT NULL,
  shared     TINYINT      NOT NULL DEFAULT 1,
  created_at DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_rt_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------- audit log
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT      NOT NULL AUTO_INCREMENT,
  account_id  VARCHAR(32) DEFAULT NULL,
  user_id     VARCHAR(32) DEFAULT NULL,
  username    VARCHAR(80) DEFAULT NULL,
  ts          DATETIME    NOT NULL,
  action      VARCHAR(60) NOT NULL,
  entity      VARCHAR(40) DEFAULT NULL,
  entity_id   VARCHAR(64) DEFAULT NULL,
  details_json LONGTEXT   DEFAULT NULL,
  ip          VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_ts (ts),
  KEY ix_audit_account (account_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------- device connections
CREATE TABLE IF NOT EXISTS device_sessions (
  id         BIGINT      NOT NULL AUTO_INCREMENT,
  unit_id    VARCHAR(32) DEFAULT NULL,
  unique_id  VARCHAR(64) DEFAULT NULL,
  protocol   VARCHAR(20) NOT NULL DEFAULT 'ips',
  login_ts   DATETIME    DEFAULT NULL,
  last_ts    DATETIME    DEFAULT NULL,
  logout_ts  DATETIME    DEFAULT NULL,
  packets    INT         NOT NULL DEFAULT 0,
  ip         VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_ds_unit (unit_id, last_ts),
  KEY ix_ds_uid (unique_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------- places (offline geocoder)
-- Used to turn coordinates into human readable addresses without any API:
-- engine::address_of() picks the nearest place below, otherwise prints the
-- decimal coordinates. Add your own rows for your operating region.
CREATE TABLE IF NOT EXISTS places (
  id      INT          NOT NULL AUTO_INCREMENT,
  name    VARCHAR(120) NOT NULL,
  kind    VARCHAR(30)  NOT NULL DEFAULT 'town', -- city|town|suburb|poi|depot|airport
  lat     DECIMAL(10,7) NOT NULL,
  lon     DECIMAL(10,7) NOT NULL,
  country VARCHAR(80)  DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_places_latlon (lat, lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- settings
CREATE TABLE IF NOT EXISTS settings (
  id             INT          NOT NULL,
  platform_name  VARCHAR(120) NOT NULL DEFAULT 'KIVU Tracker',
  company_name   VARCHAR(120) DEFAULT NULL,
  support_email  VARCHAR(160) DEFAULT NULL,
  default_currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  map_tiles      VARCHAR(255) NOT NULL DEFAULT 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  default_lat    DECIMAL(10,7) NOT NULL DEFAULT -1.2921000,
  default_lon    DECIMAL(10,7) NOT NULL DEFAULT 36.8219000,
  default_zoom   INT          NOT NULL DEFAULT 11,
  online_timeout_s   INT      NOT NULL DEFAULT 300,
  moving_speed_kmh   DECIMAL(6,2) NOT NULL DEFAULT 5,
  retention_days INT          NOT NULL DEFAULT 90,
  ips_port       INT          NOT NULL DEFAULT 20332,
  trial_days     INT          NOT NULL DEFAULT 5,               -- free trial for new accounts
  signup_enabled TINYINT      NOT NULL DEFAULT 1,               -- allow self-service registration
  gateway_mode   VARCHAR(10)  NOT NULL DEFAULT 'sandbox',       -- sandbox|live
  theme_mode     VARCHAR(10)  NOT NULL DEFAULT 'user',          -- user|dark|light (locks the theme)
  portal_price   DECIMAL(12,2) NOT NULL DEFAULT 999.00,          -- reseller portal, per full year
  invoice_prefix VARCHAR(12)  NOT NULL DEFAULT 'KIVU',
  billing_json   LONGTEXT     DEFAULT NULL,                     -- gateway credentials (see php/payments.php)
  apk_url        VARCHAR(255) DEFAULT NULL,                     -- where /download sends users (default: /download/kivu-tracker.apk)
  apk_version    VARCHAR(40)  DEFAULT NULL,                     -- shown on the download page (e.g. 1.0.0)
  apk_notes      VARCHAR(500) DEFAULT NULL,                     -- release notes / what's new
  logo_url       VARCHAR(500) DEFAULT NULL,                     -- platform logo (/img/branding/… or any URL)
  powered_by     VARCHAR(180) DEFAULT NULL,                     -- optional platform footer credit
  seed           VARCHAR(32)  DEFAULT NULL,
  installed_at   DATETIME     DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- billing
-- Subscription plans sold to accounts (trial usage is plan-independent).
CREATE TABLE IF NOT EXISTS plans (
  id             VARCHAR(32)  NOT NULL,
  code           VARCHAR(40)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  tagline        VARCHAR(200) DEFAULT NULL,
  price          DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency       VARCHAR(8)   NOT NULL DEFAULT 'USD',
  `interval`     VARCHAR(10)  NOT NULL DEFAULT 'month',   -- month|year
  unit_limit     INT          NOT NULL DEFAULT 10,
  extra_unit_price DECIMAL(8,2) NOT NULL DEFAULT 0,     -- per billing period, 0 = extras not allowed
  storage_days   INT          NOT NULL DEFAULT 90,
  trial_days     INT          NOT NULL DEFAULT 5,
  features_json  LONGTEXT     DEFAULT NULL,
  sort_order     INT          NOT NULL DEFAULT 0,
  enabled        TINYINT      NOT NULL DEFAULT 1,
  created_at     DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per subscription; the newest row per account is the current one.
CREATE TABLE IF NOT EXISTS subscriptions (
  id            VARCHAR(32)  NOT NULL,
  account_id    VARCHAR(32)  NOT NULL,
  plan_id       VARCHAR(32)  DEFAULT NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'trialing', -- trialing|active|past_due|expired|canceled|comp
  gateway       VARCHAR(20)  DEFAULT NULL,
  gateway_ref   VARCHAR(120) DEFAULT NULL,
  trial_start   DATETIME     DEFAULT NULL,
  trial_end     DATETIME     DEFAULT NULL,
  period_start  DATETIME     DEFAULT NULL,
  period_end    DATETIME     DEFAULT NULL,
  cancel_at_period_end TINYINT NOT NULL DEFAULT 0,
  created_at    DATETIME     DEFAULT NULL,
  updated_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sub_account (account_id, created_at),
  KEY idx_sub_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Invoices / payment attempts through any gateway.
CREATE TABLE IF NOT EXISTS payments (
  id            VARCHAR(32)  NOT NULL,                 -- also the invoice id
  invoice_no    VARCHAR(40)  NOT NULL,
  account_id    VARCHAR(32)  NOT NULL,
  plan_id       VARCHAR(32)  DEFAULT NULL,
  gateway       VARCHAR(20)  NOT NULL,
  method        VARCHAR(30)  DEFAULT NULL,             -- card|mobile_money|bank|sandbox
  gateway_ref   VARCHAR(160) DEFAULT NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency      VARCHAR(8)   NOT NULL DEFAULT 'USD',
  status        VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|paid|failed|canceled|refunded
  period_days   INT          NOT NULL DEFAULT 30,
  payer_name    VARCHAR(120) DEFAULT NULL,
  payer_email   VARCHAR(160) DEFAULT NULL,
  payer_phone   VARCHAR(40)  DEFAULT NULL,
  checkout_url  VARCHAR(500) DEFAULT NULL,
  failure_reason VARCHAR(255) DEFAULT NULL,
  meta_json     LONGTEXT     DEFAULT NULL,
  created_at    DATETIME     DEFAULT NULL,
  updated_at    DATETIME     DEFAULT NULL,
  paid_at       DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_invoice (invoice_no),
  KEY idx_pay_account (account_id, created_at),
  KEY idx_pay_status (status),
  KEY idx_pay_ref (gateway, gateway_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw gateway callbacks (also the idempotency guard: one row per event).
CREATE TABLE IF NOT EXISTS payment_events (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  gateway       VARCHAR(20)  NOT NULL,
  event_ref     VARCHAR(160) NOT NULL,
  kind          VARCHAR(60)  DEFAULT NULL,
  status        VARCHAR(20)  DEFAULT NULL,
  payment_id    VARCHAR(32)  DEFAULT NULL,
  payload_json  LONGTEXT     DEFAULT NULL,
  created_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_event (gateway, event_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reseller portals: a reseller buys a FULL YEAR of their own portal, then
-- creates and manages client accounts inside it (role: reseller).
CREATE TABLE IF NOT EXISTS portals (
  id            VARCHAR(32)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(80)  NOT NULL,
  owner_user_id VARCHAR(32)  DEFAULT NULL,           -- the reseller login (role 'reseller')
  status        VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- pending|active|expired|suspended
  price         DECIMAL(12,2) NOT NULL DEFAULT 0,    -- what the full year was sold for
  currency      VARCHAR(8)   NOT NULL DEFAULT 'USD',
  period_start  DATETIME     DEFAULT NULL,
  period_end    DATETIME     DEFAULT NULL,           -- one full year after the payment
  contact_email VARCHAR(160) DEFAULT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_portals_slug (slug),
  KEY idx_portals_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saved payment methods for automatic billing at trial/period end (the token
-- comes back from the gateway on the first successful payment; the raw token
-- is never returned by the API).
CREATE TABLE IF NOT EXISTS payment_methods (
  id           VARCHAR(32)  NOT NULL,
  account_id   VARCHAR(32)  NOT NULL,
  gateway      VARCHAR(20)  NOT NULL,                -- sandbox | mpesa | stripe | flutterwave …
  token        VARCHAR(255) NOT NULL,                -- PM token / agreement id / msisdn
  brand        VARCHAR(40)  DEFAULT NULL,             -- Visa / M-Pesa / PayPal …
  last4        VARCHAR(8)   DEFAULT NULL,
  exp_month    TINYINT      DEFAULT NULL,
  exp_year     INT          DEFAULT NULL,
  holder       VARCHAR(120) DEFAULT NULL,
  phone        VARCHAR(40)  DEFAULT NULL,            -- M-Pesa: the number that gets the STK push
  customer_ref VARCHAR(160) DEFAULT NULL,             -- gateway customer id (Stripe …)
  status       VARCHAR(20)  NOT NULL DEFAULT 'active',
  is_default   TINYINT      NOT NULL DEFAULT 1,
  last_used_at DATETIME     DEFAULT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pm_account (account_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment reminder e-mails (2 weeks / 5 days / 2 days before deactivation).
-- One row per (account, reminder kind, deactivation date): the unique key is
-- the de-duplication guard, so a sweep never mails the same warning twice and
-- a new trial/period unlocks fresh reminders.
CREATE TABLE IF NOT EXISTS billing_reminders (
  id           VARCHAR(32)  NOT NULL,
  account_id   VARCHAR(32)  NOT NULL,
  kind         VARCHAR(12)  NOT NULL,                 -- 14d | 5d | 2d | manual
  days_before  INT          NOT NULL,                 -- threshold the reminder was due for
  cutoff_at    DATETIME     NOT NULL,                 -- the trial/period end it refers to
  days_left    INT          NOT NULL,                 -- countdown when it was sent
  recipient    VARCHAR(190) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body         TEXT         NOT NULL,                 -- rendered HTML e-mail
  channel      VARCHAR(12)  NOT NULL DEFAULT 'smtp',  -- smtp | mail | log
  status       VARCHAR(16)  NOT NULL DEFAULT 'pending', -- sent | failed | pending
  attempts     INT          NOT NULL DEFAULT 0,
  error        VARCHAR(255) DEFAULT NULL,
  sent_at      DATETIME     DEFAULT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reminder_cycle (account_id, kind, cutoff_at),
  KEY idx_reminder_account (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
-- =====================================================================
--  End of schema. Next: open install.php once (creates demo data).
-- =====================================================================
