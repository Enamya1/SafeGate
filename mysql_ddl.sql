-- MySQL DDL Script for Campus Second-Hand Trading Application

-- 1. Users Table
CREATE TABLE Users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone_number VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    dormitory_id INT NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    failed_login_attempts INT DEFAULT 0,
    locked_until DATETIME,
    deleted_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME
);

-- 2. Roles Table
CREATE TABLE Roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    permissions JSON
);

-- 3. User_Roles Table (Junction table for Many-to-Many relationship between Users and Roles)
CREATE TABLE User_Roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(id),
    FOREIGN KEY (role_id) REFERENCES Roles(id),
    UNIQUE (user_id, role_id)
);

-- 4. Authentication_Providers Table
CREATE TABLE Authentication_Providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_user_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id),
    UNIQUE (provider, provider_user_id)
);

-- 5. Sessions Table
CREATE TABLE Sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    device_info VARCHAR(255),
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

-- 6. Verification_Tokens Table
CREATE TABLE Verification_Tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL, -- e.g., 'email_verification', 'phone_verification'
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

-- 7. Password_Resets Table
CREATE TABLE Password_Resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

-- 8. Dormitories Table
CREATE TABLE Dormitories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dormitory_name VARCHAR(255) UNIQUE NOT NULL,
    domain VARCHAR(255) UNIQUE,
    location VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE
);

-- Add foreign key to Users table after Campus table is created
ALTER TABLE Users
ADD CONSTRAINT fk_users_dormitories
FOREIGN KEY (dormitory_id) REFERENCES Dormitories(id);

-- 9. Meetup_Locations Table
CREATE TABLE Meetup_Locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dormitory_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dormitory_id) REFERENCES Dormitories(id)
);

-- 10. Categories Table (Self-referencing for parent categories)
CREATE TABLE Categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    parent_id INT,
    FOREIGN KEY (parent_id) REFERENCES Categories(id)
);

-- 11. Condition_Levels Table
CREATE TABLE Condition_Levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    sort_order INT UNIQUE
);

-- 12. Products Table
CREATE TABLE Products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    campus_id INT NOT NULL,
    category_id INT NOT NULL,
    condition_level_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL CHECK (price > 0),
    status VARCHAR(50) DEFAULT 'available', -- e.g., 'available', 'sold', 'pending', 'deleted'
    deleted_at DATETIME,
    modified_by INT, -- User who last modified the product
    modification_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES Users(id),
    FOREIGN KEY (dormitory_id) REFERENCES Dormitories(id),
    FOREIGN KEY (category_id) REFERENCES Categories(id),
    FOREIGN KEY (condition_level_id) REFERENCES Condition_Levels(id),
    FOREIGN KEY (modified_by) REFERENCES Users(id)
);

-- 13. Product_Images Table
CREATE TABLE Product_Images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    image_thumbnail_url VARCHAR(255),
    is_primary BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (product_id) REFERENCES Products(id)
);

-- 14. Tags Table
CREATE TABLE Tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL
);

-- 15. Product_Tags Table (Junction table for Many-to-Many relationship between Products and Tags)
CREATE TABLE Product_Tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    tag_id INT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES Products(id),
    FOREIGN KEY (tag_id) REFERENCES Tags(id),
    UNIQUE (product_id, tag_id)
);

-- 16. Offers Table
CREATE TABLE Offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    offer_price DECIMAL(10, 2) NOT NULL CHECK (offer_price > 0),
    status VARCHAR(50) DEFAULT 'pending', -- e.g., 'pending', 'accepted', 'rejected', 'withdrawn'
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(id),
    FOREIGN KEY (buyer_id) REFERENCES Users(id)
);

-- 17. Conversations Table
CREATE TABLE Conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(id),
    FOREIGN KEY (buyer_id) REFERENCES Users(id),
    FOREIGN KEY (seller_id) REFERENCES Users(id),
    UNIQUE (product_id, buyer_id, seller_id) -- A unique conversation between a buyer, seller, and product
);

-- 18. Messages Table
CREATE TABLE Messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id INT NOT NULL,
    message_text TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES Conversations(id),
    FOREIGN KEY (sender_id) REFERENCES Users(id)
);

-- 19. Notifications Table
CREATE TABLE Notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL, -- e.g., 'new_message', 'offer_accepted', 'product_update'
    related_entity_type VARCHAR(50), -- e.g., 'Conversation', 'Offer', 'Product'
    related_entity_id INT,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

-- 20. Transaction_Statuses Table
CREATE TABLE Transaction_Statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status_name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

-- 21. Delivery_Options Table
CREATE TABLE Delivery_Options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    fee DECIMAL(10, 2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE
);

-- 22. Transactions Table
CREATE TABLE Transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    transaction_status_id INT NOT NULL,
    meetup_location_id INT,
    delivery_option_id INT,
    final_price DECIMAL(10, 2) NOT NULL,
    completed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(id),
    FOREIGN KEY (buyer_id) REFERENCES Users(id),
    FOREIGN KEY (seller_id) REFERENCES Users(id),
    FOREIGN KEY (transaction_status_id) REFERENCES Transaction_Statuses(id),
    FOREIGN KEY (meetup_location_id) REFERENCES Meetup_Locations(id),
    FOREIGN KEY (delivery_option_id) REFERENCES Delivery_Options(id)
);

-- 23. Reviews Table
CREATE TABLE Reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNIQUE NOT NULL, -- One review per transaction
    reviewer_id INT NOT NULL,
    reviewed_user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    seller_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES Transactions(id),
    FOREIGN KEY (reviewer_id) REFERENCES Users(id),
    FOREIGN KEY (reviewed_user_id) REFERENCES Users(id)
);

-- 24. Review_Votes Table
CREATE TABLE Review_Votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    user_id INT NOT NULL,
    is_helpful BOOLEAN NOT NULL, -- TRUE for upvote, FALSE for downvote
    FOREIGN KEY (review_id) REFERENCES Reviews(id),
    FOREIGN KEY (user_id) REFERENCES Users(id),
    UNIQUE (review_id, user_id) -- A user can only vote once per review
);

-- 25. Review_Flags Table
CREATE TABLE Review_Flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (review_id) REFERENCES Reviews(id)
);

-- 26. Favorites Table
CREATE TABLE Favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id),
    FOREIGN KEY (product_id) REFERENCES Products(id),
    UNIQUE (user_id, product_id)
);

-- 27. Promoted_Listings Table
CREATE TABLE Promoted_Listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNIQUE NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    priority INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(id)
);

-- 28. Reports Table
CREATE TABLE Reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reported_by INT NOT NULL,
    reported_user_id INT, -- Can be NULL if reporting a product only
    product_id INT, -- Can be NULL if reporting a user only
    reason TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'pending', -- e.g., 'pending', 'reviewed', 'resolved', 'rejected'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reported_by) REFERENCES Users(id),
    FOREIGN KEY (reported_user_id) REFERENCES Users(id),
    FOREIGN KEY (product_id) REFERENCES Products(id)
);

-- 29. Activity_Logs Table
CREATE TABLE Activity_Logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT, -- Can be NULL for system actions
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(50), -- e.g., 'User', 'Product', 'Offer'
    entity_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

-- 30. Login_History Table
CREATE TABLE Login_History (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    device_info VARCHAR(255),
    login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

-- 31. Commission_Rates Table
CREATE TABLE Commission_Rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNIQUE NOT NULL, -- One commission rate per category
    rate DECIMAL(5, 4) NOT NULL CHECK (rate >= 0 AND rate <= 1), -- e.g., 0.05 for 5%
    effective_from DATETIME NOT NULL,
    effective_to DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES Categories(id)
);

-- Recommended Indexes
CREATE INDEX idx_products_campus_status_created_at ON Products (campus_id, status, created_at);
CREATE INDEX idx_products_category_status ON Products (category_id, status);
CREATE INDEX idx_messages_conversation_created_at ON Messages (conversation_id, created_at);
CREATE INDEX idx_transactions_buyer_seller_status ON Transactions (buyer_id, seller_id, transaction_status_id);
