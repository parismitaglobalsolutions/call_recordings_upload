# Call Recording Compliance System - API Documentation

## Base URL
```
http://your-domain.com/api/
```

---

## POST /api/call-data

Receives call and recording metadata from the mobile app.

### Request

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "user_id": "USER_123",
  "date": "2026-01-22",
  "upload_time": "2026-01-22 23:55:00",
  "calls": [
    {
      "call_id": "call_001",
      "start_time": "2026-01-22 10:23:12",
      "duration_sec": 245,
      "direction": "outgoing",
      "sim_slot": 1,
      "recording": {
        "file_name": "OUT_20260122_102312.mp3",
        "start_time": "2026-01-22 10:23:15",
        "duration_sec": 230
      }
    },
    {
      "call_id": "call_002",
      "start_time": "2026-01-22 11:45:30",
      "duration_sec": 180,
      "direction": "incoming",
      "sim_slot": 1,
      "recording": {
        "file_name": "IN_20260122_114532.mp3",
        "start_time": "2026-01-22 11:45:32",
        "duration_sec": 175
      }
    },
    {
      "call_id": "call_003",
      "start_time": "2026-01-22 14:20:00",
      "duration_sec": 120,
      "direction": "outgoing",
      "sim_slot": 2
    }
  ]
}
```

### Request Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| user_id | string | Yes | Unique identifier for the user |
| date | string | Yes | Date of the calls (YYYY-MM-DD) |
| upload_time | string | Yes | Time when data was uploaded (YYYY-MM-DD HH:MM:SS) |
| calls | array | Yes | Array of call objects |

**Call Object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| call_id | string | Yes | Unique identifier for the call |
| start_time | string | Yes | Call start time (YYYY-MM-DD HH:MM:SS) |
| duration_sec | integer | Yes | Call duration in seconds |
| direction | string | Yes | "incoming" or "outgoing" |
| sim_slot | integer | No | SIM slot number (default: 1) |
| recording | object | No | Recording metadata (if available) |

**Recording Object:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| file_name | string | Yes | Recording file name |
| start_time | string | Yes | Recording start time |
| duration_sec | integer | Yes | Recording duration in seconds |

---

### Response - Success (200 OK)

```json
{
  "success": true,
  "message": "Data processed successfully",
  "data": {
    "user_id": "USER_123",
    "date": "2026-01-22",
    "processed": {
      "calls": 3,
      "recordings": 2
    },
    "compliance": {
      "user_id": "USER_123",
      "date": "2026-01-22",
      "total_calls": 3,
      "recorded_calls": 2,
      "incoming": {
        "total": 1,
        "recorded": 1,
        "compliance": 100.00
      },
      "outgoing": {
        "total": 2,
        "recorded": 1,
        "compliance": 50.00
      },
      "overall_compliance": 66.67,
      "status": "red"
    },
    "talk_time": {
      "incoming": {
        "total_duration": 180,
        "total_duration_formatted": "3m 0s",
        "buckets": {
          "0_2": 0,
          "2_5": 1,
          "5_10": 0,
          "10_plus": 0
        }
      },
      "outgoing": {
        "total_duration": 365,
        "total_duration_formatted": "6m 5s",
        "buckets": {
          "0_2": 1,
          "2_5": 0,
          "5_10": 1,
          "10_plus": 0
        }
      }
    },
    "non_compliant_calls": 1
  }
}
```

---

### Response - Validation Error (400 Bad Request)

```json
{
  "success": false,
  "error": "Missing required field: user_id"
}
```

---

### Response - Invalid JSON (400 Bad Request)

```json
{
  "success": false,
  "error": "Invalid JSON payload"
}
```

---

### Response - Server Error (500 Internal Server Error)

```json
{
  "success": false,
  "error": "Server error: Database connection failed"
}
```

---

## Compliance Status Rules

| Compliance % | Status |
|--------------|--------|
| >= 95% | Green |
| 85% - 94% | Yellow |
| < 85% | Red |

---

## Recording Matching Rules

A recording is matched to a call when:

1. Same user
2. Same date
3. Recording start time is within:
   - 5 seconds **before** call start
   - 20 seconds **after** call start
4. Recording duration >= 70% of call duration

---

## Non-Compliance

A call is marked as **non-compliant** when no matching recording is found. This means:
- No recording object was provided for the call, OR
- The recording doesn't match the timing/duration criteria

---

## Talk Time Buckets

| Bucket | Duration Range |
|--------|----------------|
| 0-2 min | 0 - 119 seconds |
| 2-5 min | 120 - 299 seconds |
| 5-10 min | 300 - 599 seconds |
| 10+ min | 600+ seconds |

---

## cURL Example

```bash
curl -X POST http://localhost/api/call-data.php \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": "USER_123",
    "date": "2026-01-22",
    "upload_time": "2026-01-22 23:55:00",
    "calls": [
      {
        "call_id": "call_001",
        "start_time": "2026-01-22 10:23:12",
        "duration_sec": 245,
        "direction": "outgoing",
        "sim_slot": 1,
        "recording": {
          "file_name": "OUT_20260122_102312.mp3",
          "start_time": "2026-01-22 10:23:15",
          "duration_sec": 230
        }
      }
    ]
  }'
```

---

## Admin Dashboard

Access the admin dashboard at:
```
http://your-domain.com/admin/
```

**Default Credentials:**
- Username: `admin`
- Password: `admin123`

---

## Setup Instructions

1. Import the database schema:
   ```bash
   mysql -u root -p < database/schema.sql
   ```

2. Update database configuration in `config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'call_recording_db');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

3. Ensure your web server has `mod_rewrite` enabled for `.htaccess` support.

4. Set proper permissions:
   ```bash
   chmod 755 -R /path/to/project
   chmod 644 config/database.php
   ```
