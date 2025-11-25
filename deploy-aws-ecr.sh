#!/bin/bash

# AWS ECR Deployment Script for OnlyNote
# This script sets up ECR, builds and pushes the Docker image, and runs the application
set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
PROJECT_NAME="onlynote"
AWS_REGION="${AWS_REGION:-us-east-1}"
ECR_REPOSITORY_NAME="${PROJECT_NAME}"
IMAGE_TAG="${IMAGE_TAG:-latest}"

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check prerequisites
check_prerequisites() {
    log_info "Checking prerequisites..."
    
    # Check AWS CLI
    if ! command -v aws &> /dev/null; then
        log_error "AWS CLI is not installed. Please install it first: https://aws.amazon.com/cli/"
        exit 1
    fi
    
    # Check Docker
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed. Please install it first: https://docs.docker.com/get-docker/"
        exit 1
    fi
    
    # Check Docker Compose
    if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
        log_error "Docker Compose is not installed. Please install it first."
        exit 1
    fi
    
    # Check AWS credentials
    log_info "Checking AWS credentials..."
    AWS_IDENTITY_OUTPUT=$(aws sts get-caller-identity 2>&1)
    AWS_IDENTITY_EXIT_CODE=$?
    
    if [ $AWS_IDENTITY_EXIT_CODE -ne 0 ]; then
        log_error "AWS credentials are not configured or invalid."
        log_error "Error details: $AWS_IDENTITY_OUTPUT"
        echo ""
        log_info "Please configure AWS credentials using one of these methods:"
        echo "  1. Run 'aws configure' and enter your credentials"
        echo "  2. Set environment variables:"
        echo "     export AWS_ACCESS_KEY_ID=your-access-key"
        echo "     export AWS_SECRET_ACCESS_KEY=your-secret-key"
        echo "     export AWS_DEFAULT_REGION=us-east-1"
        echo "  3. If running on EC2, attach an IAM role with ECR permissions"
        echo ""
        exit 1
    fi
    
    # Display AWS identity for verification
    AWS_ACCOUNT_ID=$(echo "$AWS_IDENTITY_OUTPUT" | grep -oP '"Account":\s*"\K[^"]+' || echo "$AWS_IDENTITY_OUTPUT" | jq -r '.Account' 2>/dev/null || echo "")
    AWS_USER_ARN=$(echo "$AWS_IDENTITY_OUTPUT" | grep -oP '"Arn":\s*"\K[^"]+' || echo "$AWS_IDENTITY_OUTPUT" | jq -r '.Arn' 2>/dev/null || echo "")
    
    if [ -n "$AWS_ACCOUNT_ID" ] && [[ "$AWS_ACCOUNT_ID" =~ ^[0-9]{12}$ ]]; then
        log_success "AWS credentials verified (Account: ${AWS_ACCOUNT_ID})"
        if [ -n "$AWS_USER_ARN" ]; then
            log_info "Using identity: ${AWS_USER_ARN}"
        fi
    else
        log_warning "Could not parse AWS account ID from output"
    fi
    
    # Check if AWS region is set
    if [ -z "$AWS_REGION" ] && [ -z "$AWS_DEFAULT_REGION" ]; then
        # Try to get region from EC2 instance metadata if available
        if curl -s --max-time 1 http://169.254.169.254/latest/meta-data/placement/region &> /dev/null; then
            AWS_REGION=$(curl -s http://169.254.169.254/latest/meta-data/placement/region)
            log_info "Detected AWS region from EC2 metadata: ${AWS_REGION}"
        else
            AWS_REGION="us-east-1"
            log_warning "AWS region not set, defaulting to: ${AWS_REGION}"
        fi
    else
        AWS_REGION="${AWS_REGION:-${AWS_DEFAULT_REGION}}"
        log_info "Using AWS region: ${AWS_REGION}"
    fi
    
    log_success "All prerequisites are met"
}

# Get AWS Account ID and ECR Registry
get_aws_info() {
    log_info "Getting AWS account information..."
    AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text 2>&1)
    
    # Check if the command was successful
    if [[ "$AWS_ACCOUNT_ID" =~ ^[0-9]{12}$ ]]; then
        ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
        log_success "AWS Account ID: ${AWS_ACCOUNT_ID}"
        log_success "ECR Registry: ${ECR_REGISTRY}"
    else
        log_error "Failed to get AWS account ID: $AWS_ACCOUNT_ID"
        log_error "Please check your AWS credentials configuration."
        exit 1
    fi
}

# Create ECR repository
create_ecr_repository() {
    log_info "Setting up ECR repository..."
    
    if aws ecr describe-repositories --repository-names ${ECR_REPOSITORY_NAME} --region ${AWS_REGION} &> /dev/null; then
        log_warning "ECR repository ${ECR_REPOSITORY_NAME} already exists"
    else
        log_info "Creating ECR repository..."
        aws ecr create-repository \
            --repository-name ${ECR_REPOSITORY_NAME} \
            --region ${AWS_REGION} \
            --image-scanning-configuration scanOnPush=true \
            --encryption-configuration encryptionType=AES256
        log_success "ECR repository created"
    fi
    
    # Set lifecycle policy for Free Tier optimization
    log_info "Setting ECR lifecycle policy..."
    cat > /tmp/ecr-lifecycle-policy.json << EOF
{
  "rules": [
    {
      "rulePriority": 1,
      "description": "Keep only 10 most recent images",
      "selection": {
        "tagStatus": "any",
        "countType": "imageCountMoreThan",
        "countNumber": 10
      },
      "action": {
        "type": "expire"
      }
    },
    {
      "rulePriority": 2,
      "description": "Delete untagged images older than 1 day",
      "selection": {
        "tagStatus": "untagged",
        "countType": "sinceImagePushed",
        "countUnit": "days",
        "countNumber": 1
      },
      "action": {
        "type": "expire"
      }
    }
  ]
}
EOF
    
    aws ecr put-lifecycle-policy \
        --repository-name ${ECR_REPOSITORY_NAME} \
        --lifecycle-policy-text file:///tmp/ecr-lifecycle-policy.json \
        --region ${AWS_REGION} &> /dev/null || log_warning "Could not set lifecycle policy"
    
    rm -f /tmp/ecr-lifecycle-policy.json
}

# Login to ECR
login_to_ecr() {
    log_info "Logging in to Amazon ECR..."
    aws ecr get-login-password --region ${AWS_REGION} | \
        docker login --username AWS --password-stdin ${ECR_REGISTRY}
    log_success "Logged in to ECR"
}

# Build Docker image
build_image() {
    log_info "Building Docker image..."
    docker build -f Dockerfile.free-tier -t ${ECR_REPOSITORY_NAME}:${IMAGE_TAG} .
    log_success "Docker image built successfully"
}

# Tag and push image to ECR
push_image() {
    log_info "Tagging and pushing image to ECR..."
    
    # Tag image
    docker tag ${ECR_REPOSITORY_NAME}:${IMAGE_TAG} ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:${IMAGE_TAG}
    docker tag ${ECR_REPOSITORY_NAME}:${IMAGE_TAG} ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:latest
    
    # Push image
    docker push ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:${IMAGE_TAG}
    docker push ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:latest
    
    log_success "Image pushed to ECR successfully"
    log_info "Image URI: ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:${IMAGE_TAG}"
}

# Create docker-compose file with ECR image
create_docker_compose() {
    log_info "Creating docker-compose file with ECR image..."
    
    cat > docker-compose.ecr.yml << EOF
version: '3.8'

services:
  # MySQL Database
  mysql:
    image: mysql:8.0
    container_name: ${PROJECT_NAME}_mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: \${DB_ROOT_PASSWORD:-rootpassword}
      MYSQL_DATABASE: \${DB_DATABASE:-onlynote}
      MYSQL_USER: \${DB_USERNAME:-onlynote_user}
      MYSQL_PASSWORD: \${DB_PASSWORD:-onlynote_password}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf
    ports:
      - "3306:3306"
    networks:
      - ${PROJECT_NAME}_network
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-p\${DB_ROOT_PASSWORD:-rootpassword}"]
      timeout: 20s
      retries: 10

  # Redis Cache
  redis:
    image: redis:7-alpine
    container_name: ${PROJECT_NAME}_redis
    restart: unless-stopped
    command: redis-server --appendonly yes
    volumes:
      - redis_data:/data
      - ./docker/redis/redis.conf:/usr/local/etc/redis/redis.conf
    ports:
      - "6379:6379"
    networks:
      - ${PROJECT_NAME}_network
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      timeout: 3s
      retries: 5

  # Laravel Application (from ECR)
  app:
    image: ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:${IMAGE_TAG}
    container_name: ${PROJECT_NAME}_app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - app_storage:/var/www/html/storage
    environment:
      - APP_ENV=\${APP_ENV:-production}
      - APP_DEBUG=\${APP_DEBUG:-false}
      - DB_CONNECTION=mysql
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_DATABASE=\${DB_DATABASE:-onlynote}
      - DB_USERNAME=\${DB_USERNAME:-onlynote_user}
      - DB_PASSWORD=\${DB_PASSWORD:-onlynote_password}
      - REDIS_HOST=redis
      - REDIS_PASSWORD=\${REDIS_PASSWORD:-}
      - REDIS_PORT=6379
      - CACHE_DRIVER=redis
      - SESSION_DRIVER=redis
      - QUEUE_CONNECTION=redis
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    ports:
      - "80:80"
    networks:
      - ${PROJECT_NAME}_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

volumes:
  mysql_data:
    driver: local
  redis_data:
    driver: local
  app_storage:
    driver: local

networks:
  ${PROJECT_NAME}_network:
    driver: bridge
EOF
    
    log_success "docker-compose.ecr.yml created"
}

# Start application with docker-compose
start_application() {
    log_info "Starting application with docker-compose..."
    
    # Use docker compose if available, otherwise docker-compose
    if docker compose version &> /dev/null; then
        COMPOSE_CMD="docker compose"
    else
        COMPOSE_CMD="docker-compose"
    fi
    
    ${COMPOSE_CMD} -f docker-compose.ecr.yml up -d
    
    log_success "Application started successfully"
    log_info "Application is running at: http://localhost"
    log_info "To view logs: ${COMPOSE_CMD} -f docker-compose.ecr.yml logs -f"
    log_info "To stop: ${COMPOSE_CMD} -f docker-compose.ecr.yml down"
}

# Main execution
main() {
    echo ""
    echo "🚀 OnlyNote AWS ECR Deployment Script"
    echo "======================================"
    echo ""
    
    check_prerequisites
    get_aws_info
    create_ecr_repository
    login_to_ecr
    build_image
    push_image
    create_docker_compose
    
    echo ""
    read -p "Do you want to start the application now? (y/n) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        start_application
    else
        log_info "You can start the application later with:"
        log_info "  docker-compose -f docker-compose.ecr.yml up -d"
    fi
    
    echo ""
    log_success "Deployment completed!"
    echo ""
    echo "📦 ECR Repository Information:"
    echo "   Repository: ${ECR_REPOSITORY_NAME}"
    echo "   Registry: ${ECR_REGISTRY}"
    echo "   Image: ${ECR_REGISTRY}/${ECR_REPOSITORY_NAME}:${IMAGE_TAG}"
    echo "   Region: ${AWS_REGION}"
    echo ""
}

# Run main function
main "$@"

