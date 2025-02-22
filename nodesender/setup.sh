#!/bin/bash
# setup.sh

# Update and upgrade system packages
sudo apt update && sudo apt upgrade -y

# Install Apache, PHP, and required PHP modules along with wget and unzip
sudo apt install -y apache2 php libapache2-mod-php php-mysql php-mbstring php-xml wget unzip

# Enable Apache to start on boot and restart Apache
sudo systemctl enable apache2
sudo systemctl restart apache2

# Change to the Apache web root directory
cd /var/www/html

# Remove the default index file if it exists
sudo rm -f index.html

# Download the website zip file from S3
wget https://s3.eu-north-1.amazonaws.com/insoftstudio.com/smsdo.zip -O /tmp/smsdo.zip

# Unzip the downloaded file into the /tmp directory
sudo unzip /tmp/smsdo.zip -d /tmp/

# Copy all website files from the smsdo folder to the Apache web root
sudo cp -r /tmp/smsdo/* .

# Clean up temporary files
sudo rm -rf /tmp/smsdo
sudo rm /tmp/smsdo.zip

# Restart Apache to ensure all changes take effect
sudo systemctl restart apache2
