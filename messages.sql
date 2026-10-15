CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert the default value of 15 days
INSERT INTO system_settings (setting_key, setting_value) 
VALUES ('message_retention_days', '15')
ON DUPLICATE KEY UPDATE setting_value = setting_value;