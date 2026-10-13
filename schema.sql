-- FU-Lab • Сбор голосов коми через VK-бота
-- Базовая схема БД (MariaDB 11.4); только для пустой БД
-- Применять: mysql -u fu_lab -p fu_lab_komi < schema.sql

SET NAMES utf8mb4;
SET time_zone = '+03:00';

CREATE TABLE IF NOT EXISTS speakers (           -- дикторы
  id INT AUTO_INCREMENT PRIMARY KEY,
  vk_id BIGINT UNIQUE NOT NULL,                 -- VK user_id
  age INT NULL,
  gender ENUM('male','female','other','undeclared') DEFAULT 'undeclared',
  dialect VARCHAR(50) NULL,                     -- диалект коми
  consent TINYINT NULL,                         -- 1 = дал согласие
  state VARCHAR(20) NOT NULL DEFAULT 'new',     -- new|dialect|consent|active|declined|waiting
  last_phrase_id INT NULL,                      -- какая фраза сейчас у диктора
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phrases (            -- фразы для прочтения
  id INT AUTO_INCREMENT PRIMARY KEY,
  text TEXT NOT NULL,                           -- текст на коми
  source VARCHAR(100) NULL,                     -- откуда (учебник, словарь)
  required_times INT NOT NULL DEFAULT 1,        -- сколько записей нужно
  done_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recordings (         -- записи
  id INT AUTO_INCREMENT PRIMARY KEY,
  speaker_id INT NOT NULL,
  phrase_id INT NOT NULL,
  file VARCHAR(255) NOT NULL,                   -- относительный путь к аудио
  format VARCHAR(10) NOT NULL DEFAULT 'ogg',
  duration DOUBLE NULL,
  status ENUM('uploaded','approved','rejected') NOT NULL DEFAULT 'uploaded',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rec_speaker FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_phrase FOREIGN KEY (phrase_id) REFERENCES phrases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validations (        -- проверки (модерация)
  id INT AUTO_INCREMENT PRIMARY KEY,
  recording_id INT NOT NULL,
  validator_id INT NULL,                        -- 0 = авторизованный админ панели
  valid TINYINT NOT NULL,                       -- 1 = ок, 0 = брак
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_val_recording FOREIGN KEY (recording_id) REFERENCES recordings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Индексы для частых запросов бота
CREATE INDEX idx_phrases_pending ON phrases (done_count, required_times);
CREATE INDEX idx_rec_status ON recordings (status);
CREATE INDEX idx_rec_phrase ON recordings (phrase_id, status);

