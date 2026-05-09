# Docker Deployment Guide

## Quick Start

### Build and Run with Docker Compose

```bash
# Clone repository and navigate to project directory
cd /path/to/smart shift backend

# Copy environment template and configure
cp .env.example .env

# Build and start containers
docker-compose up -d

# Verify services are running
docker-compose ps

# View logs
docker-compose logs -f api
```

The application will be available at:
- **API**: http://localhost:3000
- **PHP Endpoints**: http://localhost:8080/api

---

## Building Docker Image Manually

### Build Image
```bash
docker build -t shiftai:latest .
```

### Run Container
```bash
docker run -d \
  --name shiftai-app \
  -p 3000:3000 \
  -e JWT_SECRET="your-secret-key" \
  -e ANTHROPIC_API_KEY="your-api-key" \
  -v $(pwd)/config.json:/app/config.json \
  shiftai:latest
```

### View Logs
```bash
docker logs -f shiftai-app
```

### Stop Container
```bash
docker stop shiftai-app
docker rm shiftai-app
```

---

## Docker Compose Commands

### Start Services
```bash
docker-compose up -d
```

### Stop Services
```bash
docker-compose down
```

### Rebuild Images
```bash
docker-compose build --no-cache
```

### View Logs
```bash
docker-compose logs -f          # All services
docker-compose logs -f api      # API only
docker-compose logs -f php      # PHP only
```

### Execute Commands in Container
```bash
docker-compose exec api node --version
docker-compose exec api npm list
```

---

## Environment Variables

Create a `.env` file in the project root with required configuration:

```bash
cp .env.example .env
```

Edit `.env` with your values:
- `JWT_SECRET`: Secret key for JWT token signing
- `ANTHROPIC_API_KEY`: API key for Claude AI integration
- `FIREBASE_*`: Firebase project credentials
- `NODE_ENV`: Set to 'production' for production builds

---

## Production Deployment

### Multi-stage Build Optimization
The Dockerfile uses multi-stage builds to:
- Reduce final image size
- Install only production dependencies
- Run as non-root user for security

### Health Checks
Container includes health checks that verify:
- API endpoint responsiveness
- Every 30 seconds
- Automatic container restart on failure

### Networking
- Services communicate via `shiftai-network` bridge network
- API runs on port 3000 (internal) → published as 3000
- PHP runs on port 80 (internal) → published as 8080

---

## Troubleshooting

### Container Won't Start
```bash
# Check logs
docker-compose logs api

# Verify port availability
lsof -i :3000

# Check environment variables
docker-compose config
```

### Out of Memory
```bash
# Increase Docker memory limit in Docker Desktop settings
# Or run with memory constraint
docker-compose run -m 2g api npm start
```

### Permission Denied Errors
Container runs as non-root user `nodejs` (UID 1001) for security.

### Network Issues
```bash
# Verify network
docker network inspect shiftai-network

# Restart network services
docker-compose down
docker-compose up -d
```

---

## File Structure in Container

```
/app
├── backend_api.js          # Main API server
├── config.json             # Configuration (persisted via volume)
├── package.json            # Node.js dependencies
├── index.html              # Worker interface
├── admin.html              # Admin interface
├── supervisor.html         # Supervisor interface
├── api/
│   └── chat.php            # PHP API endpoints
└── node_modules/           # Dependencies
```

---

## Security Notes

- Non-root user: `nodejs` (UID 1001)
- Read-only file system (except volumes)
- No privileged mode
- Network isolation via bridge network
- Environment variables for sensitive data (not in code)

---

## Additional Resources

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Reference](https://docs.docker.com/compose/compose-file/)
- [Node.js Best Practices](https://nodejs.org/en/docs/guides/)
- [Express.js Documentation](https://expressjs.com/)
