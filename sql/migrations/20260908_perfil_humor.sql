-- Perfil individual, avatar e reação diária
CREATE TABLE IF NOT EXISTS user_profiles (
  user_id INT NOT NULL PRIMARY KEY,
  avatar_path VARCHAR(255) NULL,
  mood VARCHAR(20) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_mood_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  mood VARCHAR(20) NOT NULL,
  mood_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_mood_day (user_id, mood_date),
  KEY idx_user_mood_date (mood_date, mood),
  CONSTRAINT fk_user_mood_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
