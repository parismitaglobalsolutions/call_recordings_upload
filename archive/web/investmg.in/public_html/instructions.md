📘 Call Recording Compliance & Analytics System
🎯 Objective

Build a backend + admin dashboard system that:

Receives call & recording metadata from a mobile app

Stores and processes data

Calculates compliance and talk-time metrics

Detects non-compliance

Displays results in an admin-only dashboard

Supports date-wise filtering

🧱 Tech Stack

Backend: Core PHP

Database: MySQL

Frontend: Simple Admin Dashboard (HTML + JS)

Authentication: Basic session-based login

No cron jobs

No audio processing

No token authentication

📥 API – Receive Call Data
Endpoint
POST /api/call-data

Request Payload
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
    }
  ]
}

🗄️ Database Structure
users
id
user_id
created_at

calls
id
user_id
call_id
call_start_time
call_duration
direction
sim_slot
date

recordings
id
call_id
file_name
recording_start_time
recording_duration

compliance_results
id
user_id
date
incoming_compliance
outgoing_compliance
status
created_at

🔁 Call & Recording Matching Logic

A call is matched when:

Same user

Same date

Same direction

Recording start time is within:

Call start − 5 sec

Call start + 20 sec

Recording duration ≥ 70–80% of call duration

Only one recording per call is allowed.

✅ Compliance Calculation
Formula
Compliance % = (Recorded Calls / Total Calls) × 100

Status Rules
Percentage	Status
≥ 95%	🟢 Green
85–94%	🟡 Yellow
< 85%	🔴 Red
⛔ Non-Compliance Detection

A call is non-compliant if:

Recording is missing

Duration mismatch

Recording not found

Reasons:

permission_disabled

app_not_running

system_killed

storage_issue

unknown

⏱ Talk Time Calculation
Metrics:

Total incoming talk time

Total outgoing talk time

Buckets:

0–2 minutes

2–5 minutes

5–10 minutes

10+ minutes

📊 Admin Dashboard
Features

Admin login (session-based)

User-wise compliance

Date-wise filtering

Talk time statistics

Status indicator (Green / Yellow / Red)

Charts (bar / pie)

Restrictions

Read-only

No user access

No real-time updates

📅 Date-wise Filtering

Dashboard supports:

Single date filter

Date range filter

Filters apply to:

Compliance %

Talk time

Call count

Status

⚙️ Processing Rules

No cron jobs

Processing happens:

When API is called

When admin dashboard loads

Calls after upload time are processed next day

🚫 Out of Scope

Audio playback

Audio storage

Transcription

Token-based auth

Background jobs

Cross-day call handling

✅ Final Output Expectations

Clean API structure

MySQL schema

PHP backend logic

Compliance calculation

Admin dashboard UI

Date filtering

Well-documented code

🎯 Goal for Claude

Generate:

Database schema

API implementation

Compliance logic

Admin dashboard

Filtering logic

Sample responses