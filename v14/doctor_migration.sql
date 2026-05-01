-- ══════════════════════════════════════════════
--  Sukary — Doctor Portal Migration
--  شغّل الـ SQL ده على قاعدة البيانات الحالية
--  (بيضيف جداول الدكاترة — مش بيمسح حاجة)
-- ══════════════════════════════════════════════

USE sukary;  -- أو اسم قاعدة البيانات عندك

-- ──────────────────────────────────────────────
-- 1. جدول الدكاترة
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS doctors (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(100),
  specialty     VARCHAR(100),
  clinic        VARCHAR(150),
  phone         VARCHAR(30),
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
-- 2. ربط الدكتور بالمرضى (many-to-many)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS doctor_patients (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  doctor_id  INT NOT NULL,
  patient_id INT NOT NULL,
  linked_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dp (doctor_id, patient_id),
  FOREIGN KEY (doctor_id)  REFERENCES doctors(id) ON DELETE CASCADE,
  FOREIGN KEY (patient_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
-- 3. توصيات وملاحظات الدكتور على المريض
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS doctor_notes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  doctor_id  INT NOT NULL,
  patient_id INT NOT NULL,
  type       VARCHAR(30) DEFAULT 'general',
    -- general | diet | insulin | warning | lab
  note       TEXT        NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (doctor_id)  REFERENCES doctors(id) ON DELETE CASCADE,
  FOREIGN KEY (patient_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────
--  ملاحظة: جدول goals الموجود بيتعدّل من الدكتور
--  مفيش تغيير في schema — بس الدكتور بقى قادر
--  يحدّث الأهداف بتاعة المريض من بورتاله.
-- ──────────────────────────────────────────────

-- ══════════════════════════════════════════════
--  تحقق إن كل حاجة اتعملت صح
-- ══════════════════════════════════════════════
SHOW TABLES LIKE 'doctor%';
-- المفروض يرجع: doctors, doctor_patients, doctor_notes
