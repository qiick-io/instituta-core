# Buildx bake — multi-arch Instituta images (linux/amd64 + linux/arm64).
# Usage:
#   docker buildx bake -f docker-bake.hcl production
#   REGISTRY=ghcr.io/qiick-io/instituta-core VERSION=1.0.0-beta.1 \
#     docker buildx bake -f docker-bake.hcl production --push
#
# Local single-arch (faster):
#   docker build --target production -t instituta:prod .
#
# Agents never push to GHCR; publishing is maintainer-only on a v* tag.

variable "REGISTRY" {
  default = "ghcr.io/qiick-io/instituta-core"
}

variable "VERSION" {
  default = "dev"
}

variable "TAG" {
  default = "instituta:prod"
}

variable "DEV_TAG" {
  default = "instituta:dev"
}

group "default" {
  targets = ["production"]
}

target "production" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "production"
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = [
    "${TAG}",
    "${REGISTRY}:${VERSION}",
    "${REGISTRY}:latest",
  ]
}

target "development" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "development"
  platforms  = ["linux/amd64", "linux/arm64"]
  tags       = ["${DEV_TAG}"]
}
