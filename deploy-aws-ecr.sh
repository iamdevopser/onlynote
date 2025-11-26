#!/bin/bash

# AWS ECR Microservices Deployment Script for OnlyNote
# This script builds and deploys all microservices to AWS ECR
# Designed for CI/CD pipelines - fully automated, non-interactive

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
PROJECT_NAME="onlynote"
AWS_REGION="${AWS_REGION:-us-east-1}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
SKIP_START="${SKIP_START:-false}"

# Global variables (will be set in check_prerequisites)
AWS_ACCOUNT_ID=""
ECR_REGISTRY=""

# Microservices configuration
SERVICES=("app" "mysql" "redis")
ECR_REPOSITORIES=(
    "${PROJECT_NAME}-app"
    "${PROJECT_NAME}-mysql"
    "${PROJECT_NAME}-redis"
)
DOCKERFILES=(
    "Dockerfile.app"
    "Dockerfile.mysql"
    "Dockerfile.redis"
)

# Logging functions
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
    
    # Verify AWS CLI is working
    set +e
    AWS_CLI_VERSION=$(aws --version 2>&1)
    set -e
    if [ $? -ne 0 ]; then
        log_error "AWS CLI is installed but not working properly."
        exit 1
    else
        log_info "AWS CLI version: ${AWS_CLI_VERSION}"
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
    set +e
    AWS_IDENTITY_OUTPUT=$(aws sts get-caller-identity --output json 2>&1)
    AWS_IDENTITY_EXIT_CODE=$?
    set -e
    
    if [ $AWS_IDENTITY_EXIT_CODE -ne 0 ]; then
        log_error "AWS credentials are not configured or invalid."
        log_error "Error details: $AWS_IDENTITY_OUTPUT"
        log_info "Please configure AWS credentials using:"
        echo "  1. Run 'aws configure'"
        echo "  2. Set environment variables: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY"
        echo "  3. If running on EC2, attach an IAM role with ECR permissions"
        exit 1
    fi
    
    # Parse AWS account ID
    set +e
    AWS_ACCOUNT_ID=$(echo "$AWS_IDENTITY_OUTPUT" | grep -oP '"Account":\s*"\K[^"]+' 2>/dev/null)
    if [ -z "$AWS_ACCOUNT_ID" ]; then
        AWS_ACCOUNT_ID=$(echo "$AWS_IDENTITY_OUTPUT" | jq -r '.Account' 2>/dev/null)
    fi
    set -e
    
    if [ -n "$AWS_ACCOUNT_ID" ] && [[ "$AWS_ACCOUNT_ID" =~ ^[0-9]{12}$ ]]; then
        log_success "AWS credentials verified (Account: ${AWS_ACCOUNT_ID})"
    else
        log_error "Could not parse AWS account ID"
        exit 1
    fi
    
    # Set AWS region
    if [ -z "$AWS_REGION" ] && [ -z "$AWS_DEFAULT_REGION" ]; then
        set +e
        EC2_REGION=$(curl -s --max-time 1 http://169.254.169.254/latest/meta-data/placement/region 2>/dev/null)
        set -e
        if [ -n "$EC2_REGION" ]; then
            AWS_REGION="$EC2_REGION"
            log_info "Detected AWS region from EC2 metadata: ${AWS_REGION}"
        else
            AWS_REGION="us-east-1"
            log_warning "AWS region not set, defaulting to: ${AWS_REGION}"
        fi
    else
        AWS_REGION="${AWS_REGION:-${AWS_DEFAULT_REGION}}"
        log_info "Using AWS region: ${AWS_REGION}"
    fi
    
    # Export global variables
    export AWS_ACCOUNT_ID
    export AWS_REGION
    ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
    export ECR_REGISTRY
    log_success "ECR Registry: ${ECR_REGISTRY}"
    
    log_success "All prerequisites are met"
}

# Create ECR repository for a service
create_ecr_repository() {
    local SERVICE_NAME=$1
    local REPO_NAME=$2
    
    log_info "Setting up ECR repository for ${SERVICE_NAME}..."
    
    set +e
    aws ecr describe-repositories --repository-names ${REPO_NAME} --region ${AWS_REGION} --output json &> /dev/null
    ECR_EXISTS=$?
    set -e
    
    if [ $ECR_EXISTS -eq 0 ]; then
        log_warning "ECR repository ${REPO_NAME} already exists"
    else
        log_info "Creating ECR repository ${REPO_NAME}..."
        set +e
        CREATE_OUTPUT=$(aws ecr create-repository \
            --repository-name ${REPO_NAME} \
            --region ${AWS_REGION} \
            --image-scanning-configuration scanOnPush=true \
            --encryption-configuration encryptionType=AES256 \
            --output json 2>&1)
        CREATE_EXIT=$?
        set -e
        
        if [ $CREATE_EXIT -eq 0 ]; then
            log_success "ECR repository ${REPO_NAME} created"
        else
            if echo "$CREATE_OUTPUT" | grep -q "AccessDeniedException\|not authorized"; then
                log_error "Permission denied: Cannot create ECR repository ${REPO_NAME}"
                log_error "IAM user lacks 'ecr:CreateRepository' permission"
                log_info "Please either:"
                log_info "  1. Ask your AWS administrator to grant 'ecr:CreateRepository' permission"
                log_info "  2. Create the repository manually in AWS Console"
                log_info "  3. Use an IAM user/role with ECR full access"
                log_warning "Skipping repository creation. Assuming it exists or will be created manually."
            else
                log_error "Failed to create ECR repository: $CREATE_OUTPUT"
                exit 1
            fi
        fi
    fi
    
    # Set lifecycle policy
    log_info "Setting ECR lifecycle policy for ${REPO_NAME}..."
    cat > /tmp/ecr-lifecycle-policy-${SERVICE_NAME}.json << EOF
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
    
    set +e
    aws ecr put-lifecycle-policy \
        --repository-name ${REPO_NAME} \
        --lifecycle-policy-text file:///tmp/ecr-lifecycle-policy-${SERVICE_NAME}.json \
        --region ${AWS_REGION} \
        --output json &> /dev/null
    set -e
    
    rm -f /tmp/ecr-lifecycle-policy-${SERVICE_NAME}.json
}

# Login to ECR
login_to_ecr() {
    log_info "Logging in to Amazon ECR..."
    aws ecr get-login-password --region ${AWS_REGION} | \
        docker login --username AWS --password-stdin ${ECR_REGISTRY}
    log_success "Logged in to ECR"
}

# Build Docker image for a service
build_image() {
    local SERVICE_NAME=$1
    local DOCKERFILE=$2
    local REPO_NAME=$3
    
    log_info "Building Docker image for ${SERVICE_NAME}..."
    
    if [ ! -f "$DOCKERFILE" ]; then
        log_error "Dockerfile not found: ${DOCKERFILE}"
        return 1
    fi
    
    docker build -f ${DOCKERFILE} -t ${REPO_NAME}:${IMAGE_TAG} .
    log_success "Docker image for ${SERVICE_NAME} built successfully"
}

# Tag and push image to ECR
push_image() {
    local SERVICE_NAME=$1
    local REPO_NAME=$2
    
    log_info "Tagging and pushing ${SERVICE_NAME} image to ECR..."
    
    # Tag image
    docker tag ${REPO_NAME}:${IMAGE_TAG} ${ECR_REGISTRY}/${REPO_NAME}:${IMAGE_TAG}
    docker tag ${REPO_NAME}:${IMAGE_TAG} ${ECR_REGISTRY}/${REPO_NAME}:latest
    
    # Push image
    docker push ${ECR_REGISTRY}/${REPO_NAME}:${IMAGE_TAG}
    docker push ${ECR_REGISTRY}/${REPO_NAME}:latest
    
    log_success "${SERVICE_NAME} image pushed to ECR successfully"
    log_info "Image URI: ${ECR_REGISTRY}/${REPO_NAME}:${IMAGE_TAG}"
}

# Build and push all microservices
build_and_push_all_services() {
    log_info "Building and pushing all microservices..."
    
    for i in "${!SERVICES[@]}"; do
        SERVICE_NAME=${SERVICES[$i]}
        REPO_NAME=${ECR_REPOSITORIES[$i]}
        DOCKERFILE=${DOCKERFILES[$i]}
        
        log_info "Processing microservice: ${SERVICE_NAME}"
        
        # Create ECR repository
        create_ecr_repository ${SERVICE_NAME} ${REPO_NAME}
        
        # Build image
        build_image ${SERVICE_NAME} ${DOCKERFILE} ${REPO_NAME}
        
        # Push image
        push_image ${SERVICE_NAME} ${REPO_NAME}
        
        echo ""
    done
    
    log_success "All microservices built and pushed successfully"
}

# Create docker-compose file with ECR images
create_docker_compose() {
    log_info "Creating docker-compose file with ECR images..."
    
    cat > docker-compose.ecr.yml << EOF
# Docker Compose file for OnlyNote Microservices
# All services use images from AWS ECR

services:
  # MySQL Database Service (from ECR)
  mysql:
    image: ${ECR_REGISTRY}/${PROJECT_NAME}-mysql:${IMAGE_TAG}
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
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s

  # Redis Cache Service (from ECR)
  redis:
    image: ${ECR_REGISTRY}/${PROJECT_NAME}-redis:${IMAGE_TAG}
    container_name: ${PROJECT_NAME}_redis
    restart: unless-stopped
    volumes:
      - redis_data:/data
      - ./docker/redis/redis.conf:/usr/local/etc/redis/redis.conf
    # Port mapping removed - Redis is accessible only within Docker network
    # External access not needed for microservice communication
    networks:
      - ${PROJECT_NAME}_network
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5
      start_period: 10s

  # Laravel Application Service (from ECR)
  app:
    image: ${ECR_REGISTRY}/${PROJECT_NAME}-app:${IMAGE_TAG}
    container_name: ${PROJECT_NAME}_app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - app_storage:/var/www/html/storage
      - app_bootstrap_cache:/var/www/html/bootstrap/cache
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
      - "8080:80"  # Host port 8080, container port 80
    networks:
      - ${PROJECT_NAME}_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 60s

volumes:
  mysql_data:
    driver: local
  redis_data:
    driver: local
  app_storage:
    driver: local
  app_bootstrap_cache:
    driver: local

networks:
  ${PROJECT_NAME}_network:
    driver: bridge
EOF
    
    log_success "docker-compose.ecr.yml created"
}

# Start application with docker-compose
start_application() {
    if [ "$SKIP_START" = "true" ]; then
        log_info "Skipping application start (SKIP_START=true)"
        return
    fi
    
    log_info "Starting application with docker-compose..."
    
    # Use docker compose if available, otherwise docker-compose
    if docker compose version &> /dev/null; then
        COMPOSE_CMD="docker compose"
    else
        COMPOSE_CMD="docker-compose"
    fi
    
    # Pull latest images
    log_info "Pulling latest images from ECR..."
    ${COMPOSE_CMD} -f docker-compose.ecr.yml pull
    
    # Start services
    log_info "Starting all services..."
    ${COMPOSE_CMD} -f docker-compose.ecr.yml up -d
    
    # Wait for services to be healthy
    log_info "Waiting for services to be healthy..."
    sleep 10
    
    # Check service status
    ${COMPOSE_CMD} -f docker-compose.ecr.yml ps
    
    log_success "Application started successfully"
    log_info "Application is running at: http://localhost"
    log_info "To view logs: ${COMPOSE_CMD} -f docker-compose.ecr.yml logs -f"
    log_info "To stop: ${COMPOSE_CMD} -f docker-compose.ecr.yml down"
}

# Main execution
main() {
    # Parse command line arguments
    COMPOSE_ONLY=false
    while [[ $# -gt 0 ]]; do
        case $1 in
            --compose-only)
                COMPOSE_ONLY=true
                shift
                ;;
            --help|-h)
                echo "Usage: $0 [OPTIONS]"
                echo ""
                echo "Options:"
                echo "  --compose-only    Only recreate docker-compose.ecr.yml file (skip build/push)"
                echo "  --help, -h        Show this help message"
                echo ""
                exit 0
                ;;
            *)
                log_warning "Unknown option: $1"
                shift
                ;;
        esac
    done
    
    echo ""
    echo "🚀 OnlyNote AWS ECR Microservices Deployment Script"
    echo "===================================================="
    echo ""
    
    # Check prerequisites
    check_prerequisites
    
    if [ "$COMPOSE_ONLY" = true ]; then
        log_info "Compose-only mode: Only recreating docker-compose.ecr.yml"
        # Create docker-compose file
        create_docker_compose
        log_success "docker-compose.ecr.yml recreated successfully!"
        echo ""
        exit 0
    fi
    
    # Login to ECR
    login_to_ecr
    
    # Build and push all microservices
    build_and_push_all_services
    
    # Create docker-compose file
    create_docker_compose
    
    # Start application
    start_application
    
    echo ""
    log_success "Deployment completed!"
    echo ""
    echo "📦 ECR Repository Information:"
    for i in "${!SERVICES[@]}"; do
        SERVICE_NAME=${SERVICES[$i]}
        REPO_NAME=${ECR_REPOSITORIES[$i]}
        echo "   ${SERVICE_NAME}: ${ECR_REGISTRY}/${REPO_NAME}:${IMAGE_TAG}"
    done
    echo "   Region: ${AWS_REGION}"
    echo ""
}

# Run main function
main "$@"
