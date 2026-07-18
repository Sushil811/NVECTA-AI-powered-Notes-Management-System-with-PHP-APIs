# Ultimate Free AWS Deployment Guide (RDS + Docker EC2)

This guide will walk you through exactly how to deploy your full-stack AI Note Management System for **FREE** using AWS Free Tier. We will use AWS RDS for the MySQL database and an EC2 instance to run the backend and frontend using Docker.

---

## Step 1: Create the AWS Free RDS Database (MySQL)
We want to keep the database separate from the web server for professional architecture.

1. Go to your AWS Console and search for **RDS**. Click on it.
2. Click **Create database**.
3. **Choose a database creation method**: Standard create.
4. **Engine options**: Select **MySQL**.
5. **Templates**: ⚠️ **CRITICAL: Select "Free tier"**. (If you don't do this, you will be charged).
6. **Settings**:
   - **DB instance identifier**: `mindflow-db`
   - **Master username**: `admin`
   - **Master password**: `YourSecurePassword123`
7. **Instance configuration**: Leave as default (`db.t3.micro`).
8. **Storage**: Leave as default (20 GB is free).
9. **Connectivity**: 
   - Public access: **Yes** (You can set it to No if you only want EC2 to access it, but Yes makes it easier for you to debug).
   - VPC security group: Create new (Name it `rds-sg`).
10. Click **Create database** at the bottom.
11. **IMPORTANT**: Wait 5-10 minutes for it to create. Once it says "Available", click on it and copy the **Endpoint** (It looks like `mindflow-db.cxyz.eu-west-1.rds.amazonaws.com`). This is your `DB_HOST`.

---

## Step 2: Create the Free EC2 Server
1. Search for **EC2** in the AWS Console.
2. Click **Launch Instance**.
3. **Name**: `MindFlow-Server`
4. **OS (AMI)**: Select **Ubuntu 24.04 LTS**.
5. **Instance Type**: **t2.micro** or **t3.micro** (Look for the "Free tier eligible" badge).
6. **Key Pair**: Click "Create new key pair", name it `my-key`, and download the `.pem` file. Keep this safe!
7. **Network Settings**:
   - Check **Allow SSH traffic**
   - Check **Allow HTTP traffic from the internet**
   - Check **Allow HTTPS traffic from the internet**
8. **Storage**: Change 8 GB to **20 GB** (You get up to 30GB free).
9. Click **Launch Instance**.

---

## Step 3: Connect and Install Docker
1. Once the instance is running, select it and click **Connect** at the top. You can use "EC2 Instance Connect" right in your browser (easiest), or SSH using your `.pem` file.
2. Run the following commands to install Docker:
```bash
sudo apt update
sudo apt install docker.io docker-compose -y
sudo systemctl enable docker
sudo systemctl start docker
sudo usermod -aG docker ubuntu
```
*(If you used EC2 Instance Connect, close the terminal and reconnect so the permissions apply).*

---

## Step 4: Clone the Project and Configure
1. Clone your GitHub repository to the server:
```bash
git clone https://github.com/Sushil811/NVECTA-AI-powered-Notes-Management-System-with-PHP-APIs.git nvecta
cd nvecta
```
2. Set up your Backend `.env` file:
```bash
cd backend
cp .env.example .env
nano .env
```
3. Inside the `.env` editor, update these values using the RDS Endpoint you copied in Step 1:
```env
DB_CONNECTION=mysql
DB_HOST=your-rds-endpoint.amazonaws.com
DB_PORT=3306
DB_DATABASE=nvecta
DB_USERNAME=admin
DB_PASSWORD=YourSecurePassword123

GEMINI_API_KEY=your_real_gemini_key_here
```
*(Press `Ctrl+X`, then `Y`, then `Enter` to save).*

4. Set up your Frontend `.env` file (if you have one):
```bash
cd ../frontend
nano .env
```
*(If your React app needs to know the backend URL, ensure it points to `/api` or the Public IP of your EC2 instance. In our Docker setup, Nginx handles `/api` automatically, so you may not need to change anything!).*

---

## Step 5: Launch the Application (The Magic Step)
Because I Dockerized the application for you, you don't need to install PHP, Nginx, or Composer. Just run:

```bash
cd ~/nvecta
sudo docker-compose up -d --build
```
This will take a few minutes to download the PHP and Node images, compile the React frontend, and start the servers.

Once it's done, you need to run Laravel migrations inside the Docker container to set up your RDS database tables:
```bash
sudo docker exec -it mindflow_backend php artisan migrate --force
sudo docker exec -it mindflow_backend php artisan key:generate --force
```

---

## Step 6: Access Your Live Site!
1. Go back to your AWS EC2 Console.
2. Copy the **Public IPv4 address** of your instance (e.g., `54.123.45.67`).
3. Open a new tab and paste that IP into the browser: `http://54.123.45.67`

**Congratulations! Your AI Note Management System is now live on AWS for FREE, using a professional Docker and RDS architecture!**
