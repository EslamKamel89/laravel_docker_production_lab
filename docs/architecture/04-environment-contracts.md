# Environment Contracts — Laravel Knowledge API

## 1. Purpose and scope

- Development, testing, production
- Relationship to ADR-001 through ADR-009

## 2. Shared application invariants

- Application behavior
- Runtime compatibility
- Database and vector requirements
- HTTP, queue, and scheduler responsibilities

## 3. Environment matrix

- Images and source delivery
- Mounts and writable paths
- Network exposure
- Credentials and configuration
- Debugging and tooling
- Persistence and backups

## 4. Development contract

- Editable source
- Containerized tools
- Local data isolation
- Debugging capabilities

## 5. Testing contract

- Isolated infrastructure
- Repeatable setup and cleanup
- No dependency on production state

## 6. Production contract

- Versioned immutable images
- Runtime secrets
- Private infrastructure
- Persistent state
- Controlled migrations and releases

## 7. Contract verification

- Static configuration checks
- Runtime checks
- Failure experiments

## 8. Related architecture decisions

- ADR-001 through ADR-009
- Any new decisions requiring approval
