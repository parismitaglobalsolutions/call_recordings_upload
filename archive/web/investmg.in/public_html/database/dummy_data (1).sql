-- Dummy Data for Call Recording Compliance System
-- Run this AFTER schema.sql

USE call_recording_db;

-- Clear existing data (optional)
-- TRUNCATE TABLE upload_logs;
-- TRUNCATE TABLE talk_time_stats;
-- TRUNCATE TABLE compliance_results;
-- TRUNCATE TABLE recordings;
-- TRUNCATE TABLE calls;
-- TRUNCATE TABLE users;

-- Insert Users
INSERT INTO users (user_id, created_at) VALUES
('USER_123', '2026-01-15 09:00:00'),
('USER_456', '2026-01-16 10:30:00'),
('USER_789', '2026-01-17 14:00:00'),
('USER_101', '2026-01-18 08:45:00');

-- =====================================================
-- USER_123 - Day 1 (2026-01-22) - Good compliance (100%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_123', 'call_001', '2026-01-22 10:23:12', 245, 'outgoing', 1, '2026-01-22', 1),
('USER_123', 'call_002', '2026-01-22 14:05:40', 95, 'incoming', 2, '2026-01-22', 1),
('USER_123', 'call_003', '2026-01-22 16:30:00', 180, 'outgoing', 1, '2026-01-22', 1),
('USER_123', 'call_004', '2026-01-22 18:15:20', 320, 'incoming', 1, '2026-01-22', 1);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_001', 'USER_123', 'OUT_20260122_102312.mp3', '2026-01-22 10:23:15', 230, '2026-01-22', 1),
('call_002', 'USER_123', 'IN_20260122_140540.m4a', '2026-01-22 14:05:45', 90, '2026-01-22', 2),
('call_003', 'USER_123', 'OUT_20260122_163000.mp3', '2026-01-22 16:30:03', 175, '2026-01-22', 3),
('call_004', 'USER_123', 'IN_20260122_181520.mp3', '2026-01-22 18:15:22', 310, '2026-01-22', 4);

-- =====================================================
-- USER_123 - Day 2 (2026-01-23) - Medium compliance (75%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_123', 'call_005', '2026-01-23 09:10:00', 150, 'outgoing', 1, '2026-01-23', 1),
('USER_123', 'call_006', '2026-01-23 11:20:30', 200, 'incoming', 1, '2026-01-23', 1),
('USER_123', 'call_007', '2026-01-23 14:45:00', 90, 'outgoing', 2, '2026-01-23', 1),
('USER_123', 'call_008', '2026-01-23 17:00:00', 180, 'incoming', 1, '2026-01-23', 0);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_005', 'USER_123', 'OUT_20260123_091000.mp3', '2026-01-23 09:10:02', 145, '2026-01-23', 5),
('call_006', 'USER_123', 'IN_20260123_112030.mp3', '2026-01-23 11:20:33', 195, '2026-01-23', 6),
('call_007', 'USER_123', 'OUT_20260123_144500.mp3', '2026-01-23 14:45:05', 85, '2026-01-23', 7);
-- call_008 has NO recording (non-compliant)

-- =====================================================
-- USER_456 - Day 1 (2026-01-22) - Poor compliance (50%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_456', 'call_101', '2026-01-22 08:00:00', 300, 'outgoing', 1, '2026-01-22', 1),
('USER_456', 'call_102', '2026-01-22 10:30:00', 120, 'incoming', 1, '2026-01-22', 0),
('USER_456', 'call_103', '2026-01-22 13:15:00', 450, 'outgoing', 1, '2026-01-22', 1),
('USER_456', 'call_104', '2026-01-22 15:45:00', 60, 'incoming', 2, '2026-01-22', 0);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_101', 'USER_456', 'OUT_20260122_080000.mp3', '2026-01-22 08:00:05', 290, '2026-01-22', 9),
('call_103', 'USER_456', 'OUT_20260122_131500.mp3', '2026-01-22 13:15:08', 440, '2026-01-22', 11);
-- call_102 and call_104 have NO recordings (non-compliant)

-- =====================================================
-- USER_456 - Day 2 (2026-01-23) - Good compliance (100%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_456', 'call_105', '2026-01-23 09:00:00', 180, 'outgoing', 1, '2026-01-23', 1),
('USER_456', 'call_106', '2026-01-23 12:00:00', 240, 'incoming', 1, '2026-01-23', 1);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_105', 'USER_456', 'OUT_20260123_090000.mp3', '2026-01-23 09:00:03', 175, '2026-01-23', 13),
('call_106', 'USER_456', 'IN_20260123_120000.mp3', '2026-01-23 12:00:02', 235, '2026-01-23', 14);

-- =====================================================
-- USER_789 - Day 1 (2026-01-22) - Excellent compliance (100%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_789', 'call_201', '2026-01-22 07:30:00', 600, 'outgoing', 1, '2026-01-22', 1),
('USER_789', 'call_202', '2026-01-22 11:00:00', 720, 'incoming', 1, '2026-01-22', 1),
('USER_789', 'call_203', '2026-01-22 14:30:00', 150, 'outgoing', 2, '2026-01-22', 1);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_201', 'USER_789', 'OUT_20260122_073000.mp3', '2026-01-22 07:30:02', 595, '2026-01-22', 15),
('call_202', 'USER_789', 'IN_20260122_110000.mp3', '2026-01-22 11:00:01', 715, '2026-01-22', 16),
('call_203', 'USER_789', 'OUT_20260122_143000.mp3', '2026-01-22 14:30:04', 145, '2026-01-22', 17);

-- =====================================================
-- USER_789 - Day 2 (2026-01-23) - Poor compliance (33%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_789', 'call_204', '2026-01-23 08:00:00', 180, 'outgoing', 1, '2026-01-23', 1),
('USER_789', 'call_205', '2026-01-23 10:00:00', 90, 'incoming', 1, '2026-01-23', 0),
('USER_789', 'call_206', '2026-01-23 15:00:00', 120, 'outgoing', 1, '2026-01-23', 0);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_204', 'USER_789', 'OUT_20260123_080000.mp3', '2026-01-23 08:00:03', 175, '2026-01-23', 18);
-- call_205 and call_206 have NO recordings (non-compliant)

-- =====================================================
-- USER_101 - Day 1 (2026-01-22) - Medium compliance (80%)
-- =====================================================
INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date, is_compliant) VALUES
('USER_101', 'call_301', '2026-01-22 09:00:00', 240, 'outgoing', 1, '2026-01-22', 1),
('USER_101', 'call_302', '2026-01-22 11:30:00', 180, 'incoming', 1, '2026-01-22', 1),
('USER_101', 'call_303', '2026-01-22 14:00:00', 300, 'outgoing', 1, '2026-01-22', 1),
('USER_101', 'call_304', '2026-01-22 16:00:00', 90, 'incoming', 2, '2026-01-22', 1),
('USER_101', 'call_305', '2026-01-22 18:00:00', 150, 'outgoing', 1, '2026-01-22', 0);

INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date, matched_call_id) VALUES
('call_301', 'USER_101', 'OUT_20260122_090000.mp3', '2026-01-22 09:00:02', 235, '2026-01-22', 21),
('call_302', 'USER_101', 'IN_20260122_113000.mp3', '2026-01-22 11:30:03', 175, '2026-01-22', 22),
('call_303', 'USER_101', 'OUT_20260122_140000.mp3', '2026-01-22 14:00:05', 290, '2026-01-22', 23),
('call_304', 'USER_101', 'IN_20260122_160000.mp3', '2026-01-22 16:00:02', 85, '2026-01-22', 24);
-- call_305 has NO recording (non-compliant)

-- =====================================================
-- Compliance Results (Pre-calculated)
-- =====================================================
INSERT INTO compliance_results (user_id, date, total_calls, recorded_calls, incoming_total, incoming_recorded, incoming_compliance, outgoing_total, outgoing_recorded, outgoing_compliance, overall_compliance, status) VALUES
('USER_123', '2026-01-22', 4, 4, 2, 2, 100.00, 2, 2, 100.00, 100.00, 'green'),
('USER_123', '2026-01-23', 4, 3, 2, 1, 50.00, 2, 2, 100.00, 75.00, 'red'),
('USER_456', '2026-01-22', 4, 2, 2, 0, 0.00, 2, 2, 100.00, 50.00, 'red'),
('USER_456', '2026-01-23', 2, 2, 1, 1, 100.00, 1, 1, 100.00, 100.00, 'green'),
('USER_789', '2026-01-22', 3, 3, 1, 1, 100.00, 2, 2, 100.00, 100.00, 'green'),
('USER_789', '2026-01-23', 3, 1, 1, 0, 0.00, 2, 1, 50.00, 33.33, 'red'),
('USER_101', '2026-01-22', 5, 4, 2, 2, 100.00, 3, 2, 66.67, 80.00, 'red');

-- =====================================================
-- Talk Time Statistics
-- =====================================================
INSERT INTO talk_time_stats (user_id, date, direction, total_duration, bucket_0_2, bucket_2_5, bucket_5_10, bucket_10_plus) VALUES
-- USER_123 Day 1
('USER_123', '2026-01-22', 'incoming', 415, 1, 0, 1, 0),
('USER_123', '2026-01-22', 'outgoing', 425, 0, 1, 1, 0),
-- USER_123 Day 2
('USER_123', '2026-01-23', 'incoming', 380, 0, 1, 1, 0),
('USER_123', '2026-01-23', 'outgoing', 240, 1, 1, 0, 0),
-- USER_456 Day 1
('USER_456', '2026-01-22', 'incoming', 180, 1, 1, 0, 0),
('USER_456', '2026-01-22', 'outgoing', 750, 0, 1, 0, 1),
-- USER_456 Day 2
('USER_456', '2026-01-23', 'incoming', 240, 0, 1, 0, 0),
('USER_456', '2026-01-23', 'outgoing', 180, 0, 1, 0, 0),
-- USER_789 Day 1
('USER_789', '2026-01-22', 'incoming', 720, 0, 0, 0, 1),
('USER_789', '2026-01-22', 'outgoing', 750, 1, 0, 1, 0),
-- USER_789 Day 2
('USER_789', '2026-01-23', 'incoming', 90, 1, 0, 0, 0),
('USER_789', '2026-01-23', 'outgoing', 300, 0, 1, 1, 0),
-- USER_101 Day 1
('USER_101', '2026-01-22', 'incoming', 270, 1, 1, 0, 0),
('USER_101', '2026-01-22', 'outgoing', 690, 0, 2, 1, 0);

-- =====================================================
-- Upload Logs
-- =====================================================
INSERT INTO upload_logs (user_id, date, upload_time, calls_count, recordings_count) VALUES
('USER_123', '2026-01-22', '2026-01-22 23:55:00', 4, 4),
('USER_123', '2026-01-23', '2026-01-23 23:50:00', 4, 3),
('USER_456', '2026-01-22', '2026-01-22 23:45:00', 4, 2),
('USER_456', '2026-01-23', '2026-01-23 23:40:00', 2, 2),
('USER_789', '2026-01-22', '2026-01-22 23:30:00', 3, 3),
('USER_789', '2026-01-23', '2026-01-23 23:35:00', 3, 1),
('USER_101', '2026-01-22', '2026-01-22 23:20:00', 5, 4);
