CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


INSERT IGNORE INTO departments (dept_name) VALUES 
('IT'), 
('Finance'), 
('Front Office'), 
('HouseKeeping'),
('Engineering'),
('F&B Banquet'),
('F&B Kitchen'),
('F&B Service'),
('Sales & Marketing'),
('Human Resource');