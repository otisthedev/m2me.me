# Match Me Quiz API Documentation

WordPress REST API endpoints for quiz submission, result retrieval, and matching.

## Base URL

```
/wp-json/match-me/v1
```

## Authentication

Most endpoints require WordPress nonce authentication. Include the nonce in request headers:

```
X-WP-Nonce: {nonce_value}
```

The nonce is available in JavaScript via `window.cqVars.nonce`.

## Endpoints

### 1. Submit Quiz

Submit quiz answers and receive calculated trait vector and share token.

**Endpoint:** `POST /wp-json/match-me/v1/quiz/{quiz_id}/submit`

**Parameters:**
- `quiz_id` (path, required): Quiz identifier (slug from JSON filename)

**Request Body:**
```json
{
  "answers": [
    {
      "question_id": "q1",
      "option_id": "opt_1",
      "value": 1
    }
  ],
  "share_mode": "share_match",
  "anonymous_meta": {
    "session_id": "optional_session_id"
  }
}
```

**Response (200 OK):**
```json
{
  "result_id": 123,
  "trait_vector": {
    "directness": 0.75,
    "empathy": 0.60,
    "clarity": 0.90
  },
  "share_token": "a3f9k2m8p1q7r4s6t0u5v2w9x1y3z8",
  "share_urls": {
    "view": "https://example.com/result/a3f9k2m8p1q7r4s6t0u5v2w9x1y3z8",
    "compare": "https://example.com/compare/a3f9k2m8p1q7r4s6t0u5v2w9x1y3z8"
  },
  "quiz_version": "1.0"
}
```

**Error Responses:**
- `400 Bad Request`: Invalid answers or missing required fields
- `404 Not Found`: Quiz not found
- `500 Internal Server Error`: Calculation or storage failure

**cURL Example:**
```bash
curl -X POST "https://example.com/wp-json/match-me/v1/quiz/communication-style-v1/submit" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  -d '{
    "answers": [
      {"question_id": "q1", "option_id": "opt_1"},
      {"question_id": "q2", "option_id": "opt_2"}
    ],
    "share_mode": "share_match"
  }'
```

---

### 2. Get Result

Retrieve a result by share token (view-only access).

**Endpoint:** `GET /wp-json/match-me/v1/result/{share_token}`

**Parameters:**
- `share_token` (path, required): 32+ character share token

**Response (200 OK):**
```json
{
  "result_id": 123,
  "quiz_title": "Communication Style",
  "trait_summary": {
    "directness": 0.75,
    "empathy": 0.60,
    "clarity": 0.90
  },
  "textual_summary": "Your communication style shows high clarity...",
  "share_mode": "share_match",
  "can_compare": true,
  "created_at": "2024-01-15T10:30:00Z"
}
```

**Error Responses:**
- `404 Not Found`: Token invalid or result not found
- `403 Forbidden`: Token revoked or share_mode is 'private'

**cURL Example:**
```bash
curl "https://example.com/wp-json/match-me/v1/result/a3f9k2m8p1q7r4s6t0u5v2w9x1y3z8"
```

---

### 3. Compare Results

Compare two results and get match score with breakdown.

**Endpoint:** `POST /wp-json/match-me/v1/result/{share_token}/compare`

**Parameters:**
- `share_token` (path, required): Share token of first result

**Request Body (Option A - Use existing result):**
```json
{
  "result_id": 456
}
```

**Request Body (Option B - Calculate from answers):**
```json
{
  "quiz_id": "communication-style-v1",
  "answers": [
    {"question_id": "q1", "option_id": "opt_2"}
  ],
  "algorithm": "cosine"
}
```

**Response (200 OK):**
```json
{
  "comparison_id": 789,
  "match_score": 87.5,
  "breakdown": {
    "overall": 87.5,
    "traits": {
      "directness": {
        "a": 0.75,
        "b": 0.70,
        "similarity": 0.99
      },
      "empathy": {
        "a": 0.60,
        "b": 0.65,
        "similarity": 0.98
      },
      "clarity": {
        "a": 0.90,
        "b": 0.85,
        "similarity": 0.99
      }
    }
  },
  "algorithm_used": "cosine"
}
```

**Error Responses:**
- `400 Bad Request`: Invalid answers or missing required fields
- `403 Forbidden`: Share mode doesn't allow comparison
- `404 Not Found`: Result not found
- `429 Too Many Requests`: Rate limit exceeded (10 requests/hour per IP)

**Rate Limiting:**
- Maximum 10 comparisons per IP address per hour
- Returns `429 Too Many Requests` with `Retry-After` header when exceeded

**cURL Example:**
```bash
curl -X POST "https://example.com/wp-json/match-me/v1/result/a3f9k2m8p1q7r4s6t0u5v2w9x1y3z8/compare" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  -d '{
    "quiz_id": "communication-style-v1",
    "answers": [
      {"question_id": "q1", "option_id": "opt_2"}
    ],
    "algorithm": "cosine"
  }'
```

---

### 4. Revoke Token

Revoke a share token (owner only).

**Endpoint:** `POST /wp-json/match-me/v1/result/{result_id}/revoke`

**Parameters:**
- `result_id` (path, required): Result ID

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Token revoked successfully"
}
```

**Error Responses:**
- `404 Not Found`: Result not found
- `403 Forbidden`: User doesn't own this result

**cURL Example:**
```bash
curl -X POST "https://example.com/wp-json/match-me/v1/result/123/revoke" \
  -H "X-WP-Nonce: {nonce}"
```

---

## Data Types

### Answer Object
```json
{
  "question_id": "string (required)",
  "option_id": "string (required)",
  "value": "number (optional)"
}
```

### Trait Vector
Object mapping trait names to normalized values [0..1]:
```json
{
  "trait_name": 0.75
}
```

### Match Breakdown
```json
{
  "overall": 87.5,
  "traits": {
    "trait_name": {
      "a": 0.75,
      "b": 0.70,
      "similarity": 0.99
    }
  }
}
```

## Algorithms

### Cosine Similarity (default)
- Formula: `cos(θ) = (A · B) / (||A|| × ||B||)`
- Best for: Normalized vectors, direction-based similarity
- Range: 0-100%

### Euclidean Distance
- Formula: `distance = sqrt(Σ((a_i - b_i)²))`, then `match = 100 × (1 - distance/max_distance)`
- Best for: Magnitude-based comparisons
- Range: 0-100%

### Absolute Difference
- Formula: `match = 100 × (1 - mean(|a_i - b_i|))`
- Best for: Simple, interpretable matching
- Range: 0-100%

## Share Modes

- `private`: No sharing, only owner can view
- `share`: View-only sharing via token
- `share_match`: Token allows viewing and comparing

## Error Handling

All errors follow this format:
```json
{
  "code": "error_code",
  "message": "Human-readable error message",
  "data": {
    "status": 400
  }
}
```

Common error codes:
- `invalid_answers`: Answers array is invalid or empty
- `not_found`: Resource not found
- `forbidden`: Access denied
- `rate_limit`: Too many requests
- `server_error`: Internal server error

## Rate Limiting

The compare endpoint is rate-limited to prevent abuse:
- **Limit**: 10 requests per IP address per hour
- **Response**: HTTP 429 with `Retry-After` header
- **Implementation**: WordPress transients

## Security

- Share tokens are 32+ character base62 random strings (unguessable)
- Tokens are permanent until explicitly revoked
- Rate limiting prevents abuse
- User ownership verified for revoke endpoint
- Login requirement configurable via admin settings

## Versioning

- Each result is locked to the quiz version at creation time
- Results can only match with same-version results by default
- Version migration attempts automatic conversion when possible

## Examples

### Complete Flow

1. **Submit Quiz:**
```bash
POST /wp-json/match-me/v1/quiz/communication-style-v1/submit
→ Receive result_id and share_token
```

2. **Get Result:**
```bash
GET /wp-json/match-me/v1/result/{share_token}
→ View result summary
```

3. **Compare:**
```bash
POST /wp-json/match-me/v1/result/{share_token}/compare
→ Get match score and breakdown
```

4. **Revoke (optional):**
```bash
POST /wp-json/match-me/v1/result/{result_id}/revoke
→ Disable sharing
```


