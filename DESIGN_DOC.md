# Quiz & Matching System Design Document

## 1. Core Data Model

### Database Schema

#### `quizzes` Table
- `quiz_id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `slug` (VARCHAR(100), UNIQUE, NOT NULL) - URL-friendly identifier
- `title` (VARCHAR(255), NOT NULL)
- `version` (VARCHAR(20), NOT NULL, DEFAULT '1.0') - Semantic versioning
- `aspect_id` (BIGINT UNSIGNED, NULLABLE, FOREIGN KEY → aspects.aspect_id) - Links quiz to an aspect
- `meta` (JSON, NULLABLE) - Additional metadata (description, instructions, etc.)
- `created_at` (DATETIME, NOT NULL, DEFAULT CURRENT_TIMESTAMP)
- `updated_at` (DATETIME, NULLABLE, ON UPDATE CURRENT_TIMESTAMP)

**Indexes:**
- PRIMARY KEY (`quiz_id`)
- UNIQUE KEY (`slug`)
- KEY (`aspect_id`)

#### `questions` Table
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `quiz_id` (BIGINT UNSIGNED, NOT NULL, FOREIGN KEY → quizzes.quiz_id)
- `text` (TEXT, NOT NULL) - Question text
- `options_json` (JSON, NOT NULL) - Array of answer options with text and metadata
- `weight` (DECIMAL(5,2), NOT NULL, DEFAULT 1.00) - Question weight for scoring
- `trait_map` (JSON, NOT NULL) - Maps option_id → trait contributions
- `order_index` (INT UNSIGNED, NOT NULL, DEFAULT 0) - Display order
- `created_at` (DATETIME, NOT NULL, DEFAULT CURRENT_TIMESTAMP)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY (`quiz_id`, `order_index`)

**Example `trait_map` structure:**
```json
{
  "option_1": {"directness": 2, "empathy": 1, "clarity": 0},
  "option_2": {"directness": 0, "empathy": 2, "clarity": 1},
  "option_3": {"directness": 1, "empathy": 0, "clarity": 2}
}
```

#### `results` Table
- `result_id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `quiz_id` (BIGINT UNSIGNED, NOT NULL, FOREIGN KEY → quizzes.quiz_id)
- `user_id` (BIGINT UNSIGNED, NULLABLE, FOREIGN KEY → wp_users.ID) - NULL for anonymous
- `trait_vector` (JSON, NOT NULL) - Normalized trait vector [0..1] per trait
- `share_token` (VARCHAR(64), UNIQUE, NOT NULL) - 32+ char base62 unguessable token
- `share_mode` (ENUM('private', 'share', 'share_match'), NOT NULL, DEFAULT 'private')
  - `private`: No sharing, only owner can view
  - `share`: View-only sharing via token
  - `share_match`: Token allows others to take quiz and compare
- `quiz_version` (VARCHAR(20), NOT NULL) - Version of quiz when result was created
- `created_at` (DATETIME, NOT NULL, DEFAULT CURRENT_TIMESTAMP)
- `revoked_at` (DATETIME, NULLABLE) - When token was revoked

**Indexes:**
- PRIMARY KEY (`result_id`)
- UNIQUE KEY (`share_token`)
- KEY (`quiz_id`, `user_id`)
- KEY (`quiz_version`)
- KEY (`share_mode`, `revoked_at`)

**Example `trait_vector` structure:**
```json
{
  "directness": 0.75,
  "empathy": 0.60,
  "clarity": 0.90
}
```

#### `comparisons` Table
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `result_a` (BIGINT UNSIGNED, NOT NULL, FOREIGN KEY → results.result_id)
- `result_b` (BIGINT UNSIGNED, NOT NULL, FOREIGN KEY → results.result_id)
- `match_score` (DECIMAL(5,2), NOT NULL) - Overall match percentage (0-100)
- `breakdown` (JSON, NOT NULL) - Per-aspect match scores and trait-level breakdown
- `algorithm_used` (VARCHAR(50), NOT NULL, DEFAULT 'cosine') - Which algorithm was used
- `created_at` (DATETIME, NOT NULL, DEFAULT CURRENT_TIMESTAMP)

**Indexes:**
- PRIMARY KEY (`id`)
- KEY (`result_a`, `result_b`)
- KEY (`created_at`)

**Example `breakdown` structure:**
```json
{
  "overall": 87.5,
  "aspects": {
    "communication-style": {
      "match_score": 87.5,
      "traits": {
        "directness": {"a": 0.75, "b": 0.70, "similarity": 0.99},
        "empathy": {"a": 0.60, "b": 0.65, "similarity": 0.98},
        "clarity": {"a": 0.90, "b": 0.85, "similarity": 0.99}
      }
    }
  }
}
```

#### `aspects` Table
- `aspect_id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `slug` (VARCHAR(100), UNIQUE, NOT NULL) - URL-friendly identifier
- `title` (VARCHAR(255), NOT NULL)
- `weight` (DECIMAL(5,2), NOT NULL, DEFAULT 1.00) - Weight for aggregation in overall matching
- `description` (TEXT, NULLABLE)
- `created_at` (DATETIME, NOT NULL, DEFAULT CURRENT_TIMESTAMP)

**Indexes:**
- PRIMARY KEY (`aspect_id`)
- UNIQUE KEY (`slug`)

## 2. Calculation Model

### Trait Vector Generation Process

1. **Answer Collection**: User submits answers as `[{question_id, option_id, value}]`
2. **Raw Score Accumulation**: 
   - For each answer, look up `trait_map[option_id]` in the question's configuration
   - Multiply each trait contribution by the question's `weight`
   - Sum all contributions per trait: `raw_trait[t] = Σ(weight_q × trait_map[option][t])`
3. **Normalization**: 
   - Calculate min/max possible values per trait based on quiz configuration
   - Apply min-max normalization: `normalized[t] = (raw[t] - min[t]) / (max[t] - min[t])`
   - Clamp to [0, 1] range
4. **Storage**: Store normalized vector in `results.trait_vector` as JSON

### Aspect Aggregation

- Each quiz belongs to exactly one aspect (via `quizzes.aspect_id`)
- When matching users with multiple completed aspects:
  - Calculate match per aspect using trait vectors
  - Weight each aspect match by `aspects.weight`
  - Aggregate: `overall_match = Σ(weight_i × match_i) / Σ(weight_i)`

### Partial Aspect Matching

- Users can have results for different aspects
- Matching only occurs on aspects both users have completed
- If user A has `[communication-style, conflict-resolution]` and user B has `[communication-style, decision-making]`, only `communication-style` is used for matching
- Overall match is calculated only from shared aspects

## 3. Matching Algorithm

### Primary Algorithm: Weighted Cosine Similarity

**Formula:**
```
match_score = 100 × (Σ(w_i × cos(θ_i)) / Σ(w_i))
```

Where:
- `w_i` = weight of aspect i
- `cos(θ_i)` = cosine similarity between trait vectors for aspect i
- Cosine similarity: `cos(θ) = (A · B) / (||A|| × ||B||)`

**Detailed Calculation:**
1. For each shared aspect:
   - Extract trait vectors `A` and `B` from results
   - Calculate dot product: `A · B = Σ(a_i × b_i)`
   - Calculate magnitudes: `||A|| = sqrt(Σ(a_i²))`, `||B|| = sqrt(Σ(b_i²))`
   - Cosine similarity: `cos(θ) = (A · B) / (||A|| × ||B||)`
2. Weight each aspect's cosine similarity by aspect weight
3. Aggregate: `overall = 100 × (Σ(w_i × cos_i) / Σ(w_i))`

**Justification:**
- Cosine similarity is scale-invariant (works with normalized vectors)
- Bounded to [0, 1] range (perfect for percentage conversion)
- Handles different vector magnitudes gracefully
- Weighted aggregation allows configurable aspect importance
- Well-established in recommendation systems and similarity matching

### Fallback Algorithm 1: Weighted Euclidean Distance

**Formula:**
```
distance = sqrt(Σ(w_i × Σ((a_j - b_j)²)))
match = 100 × (1 - distance / max_distance)
```

Where:
- `max_distance` = maximum possible Euclidean distance (calculated from trait ranges)
- Applied per aspect, then weighted aggregation

**Use Case**: When cosine similarity produces unexpected results or for specific trait comparison needs

### Fallback Algorithm 2: Average Absolute Difference

**Formula:**
```
match = 100 × (1 - mean(|a_i - b_i|))
```

**Use Case**: Simple, interpretable matching for non-technical stakeholders

### Algorithm Selection

- Primary (cosine) used by default
- Fallback algorithms available via API parameter `?algorithm=euclidean` or `?algorithm=absolute`
- Algorithm used stored in `comparisons.algorithm_used` for audit trail

## 4. Security & Privacy

### Share Token Generation

- **Length**: 32+ characters (base62 encoded)
- **Generation**: `bin2hex(random_bytes(16))` → base62 encode → 32+ chars
- **Uniqueness**: Enforced via UNIQUE database constraint
- **Permanence**: Tokens do not expire; revoked only via explicit action
- **Example**: `a3f9k2m8p1q7r4s6t0u5v2w9x1y3z8b2c4d6e8f0`

### Data Privacy

- **Raw Answers**: Never returned to external viewers (only to result owner)
- **External Viewers Receive**:
  - Trait vector (normalized, anonymized)
  - Textual summary (generated from trait vector)
  - Match percentages
- **Result Owner Receives**:
  - Full trait vector
  - Raw answers (if stored)
  - Comparison breakdowns

### Rate Limiting

- **Compare Endpoint**: 10 comparisons per IP address per hour
- **Implementation**: WordPress transients with key `match_me_compare_{ip}_{hour}`
- **Response**: HTTP 429 Too Many Requests with `Retry-After` header

### Share Modes

1. **private**: No sharing allowed, only owner can view
2. **share**: Token allows view-only access to result summary
3. **share_match**: Token allows others to:
   - View result summary
   - Take the same quiz
   - Compare their result with the shared result

## 5. Versioning & Migration

### Version Locking

- Each result stores `quiz_version` at creation time
- Results can only match with same-version results by default
- Version format: Semantic versioning (e.g., "1.0", "1.1", "2.0")

### Migration Strategy

1. **Automatic Migration Attempt**:
   - When quiz version changes, attempt to map old trait vectors to new schema
   - If trait names unchanged: Direct mapping possible
   - If trait names changed but mapping exists: Apply transformation
   - If compatible: Update `quiz_version` in result record
2. **Fallback to Incompatible**:
   - If migration fails or traits incompatible: Mark result as version-locked
   - Version-locked results:
     - Can only match with same-version results
     - Display warning in UI: "This result uses an older quiz version"
     - Option to retake quiz to get new version result
3. **Migration Metadata**:
   - Store migration attempt in result metadata
   - Track which version migrated from/to

### Version Compatibility Matrix

| Old Version | New Version | Compatible? | Migration Strategy |
|------------|-------------|-------------|-------------------|
| 1.0        | 1.1         | Yes         | Direct mapping (traits unchanged) |
| 1.0        | 2.0         | Maybe       | Check trait mapping config |
| 1.0        | 2.0         | No          | Mark as incompatible, require retake |

## 6. UX Flow

### Primary Flow: Quiz Submission & Result

1. **User Takes Quiz**:
   - Frontend collects answers: `[{question_id: "q1", option_id: "opt_1", value: 1}]`
   - Submit via AJAX: `POST /wp-json/match-me/v1/quiz/{quiz_id}/submit`
2. **Backend Processing**:
   - Validate answers against quiz configuration
   - Calculate trait vector using `QuizCalculator`
   - Generate share token
   - Store result in database
   - Return: `{result_id, trait_vector, share_token, share_urls: {view, compare}}`
3. **Result Display**:
   - Show trait vector summary (textual + visual)
   - Display "Compare with others" CTA
   - Show share options (view-only or match-enabled)

### Sharing Flow

1. **User Clicks Share**:
   - Select share mode: `share` (view-only) or `share_match` (match-enabled)
   - Backend updates `results.share_mode` and generates token if needed
   - Return share URL: `https://example.com/result/{share_token}`
2. **Recipient Accesses Share Link**:
   - `GET /wp-json/match-me/v1/result/{share_token}`
   - Backend validates token, checks `share_mode` and `revoked_at`
   - Return view-only result summary (no raw answers)

### Comparison Flow

1. **User Wants to Compare**:
   - Option A: User has share token → `POST /wp-json/match-me/v1/result/{share_token}/compare` with their answers
   - Option B: User has result_id → `POST /wp-json/match-me/v1/result/{result_id}/compare` with other result_id
2. **Backend Processing**:
   - If answers provided: Calculate second trait vector
   - Load both trait vectors
   - Compute match using `MatchingService`
   - Store comparison in `comparisons` table
   - Return: `{match_score, breakdown, comparison_id}`
3. **Match Display**:
   - Show overall match percentage
   - Display per-aspect breakdown
   - Show trait-level similarities
   - Option to share comparison result

### Partial Results State

- If user has completed some aspects but not all:
  - Display completed aspects with scores
  - Show "Complete more aspects for better matching" message
  - Allow comparison on completed aspects only
  - Match score calculated only from shared completed aspects

## 7. API Specification

### Endpoints

#### `POST /wp-json/match-me/v1/quiz/{quiz_id}/submit`

**Request:**
```json
{
  "answers": [
    {"question_id": "q1", "option_id": "opt_1", "value": 1}
  ],
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

**Errors:**
- `400 Bad Request`: Invalid answers or missing required fields
- `404 Not Found`: Quiz not found
- `500 Internal Server Error`: Calculation or storage failure

#### `GET /wp-json/match-me/v1/result/{share_token}`

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

**Errors:**
- `404 Not Found`: Token invalid or result not found
- `403 Forbidden`: Token revoked or share_mode is 'private'

#### `POST /wp-json/match-me/v1/result/{share_token}/compare`

**Request:**
```json
{
  "answers": [
    {"question_id": "q1", "option_id": "opt_2", "value": 1}
  ]
}
```

OR

```json
{
  "result_id": 456
}
```

**Response (200 OK):**
```json
{
  "comparison_id": 789,
  "match_score": 87.5,
  "breakdown": {
    "overall": 87.5,
    "aspects": {
      "communication-style": {
        "match_score": 87.5,
        "traits": {
          "directness": {"a": 0.75, "b": 0.70, "similarity": 0.99},
          "empathy": {"a": 0.60, "b": 0.65, "similarity": 0.98},
          "clarity": {"a": 0.90, "b": 0.85, "similarity": 0.99}
        }
      }
    }
  },
  "algorithm_used": "cosine"
}
```

**Errors:**
- `400 Bad Request`: Invalid answers or missing required fields
- `403 Forbidden`: Share mode doesn't allow comparison
- `429 Too Many Requests`: Rate limit exceeded
- `500 Internal Server Error`: Calculation failure

#### `POST /wp-json/match-me/v1/result/{result_id}/revoke`

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Token revoked successfully"
}
```

**Errors:**
- `404 Not Found`: Result not found
- `403 Forbidden`: User doesn't own this result

## 8. Admin Configuration

### Aspect Weights

- Admin can configure `aspects.weight` via WordPress admin panel
- Default weight: 1.0 (equal importance)
- Weights can be adjusted to emphasize certain aspects in overall matching
- Changes affect future comparisons only (existing comparisons unchanged)

### Login Requirement Toggle

- Already implemented in `ThemeConfig::requireLoginForResults()`
- WordPress option: `match_me_require_login_for_results` ('1' or '0')
- When enabled: Users must be logged in to view/share results
- When disabled: Anonymous users can take quizzes and share results

### Token Management

- Admin can view all share tokens
- Admin can revoke tokens (sets `revoked_at` timestamp)
- Revoked tokens return 403 Forbidden on access attempts

## 9. Example: Communication Style Quiz

### Quiz Configuration

**Slug**: `communication-style-v1`  
**Version**: `1.0`  
**Aspect**: `communication-style`  
**Traits**: `directness`, `empathy`, `clarity`

### Questions (6 total)

1. "When giving feedback, you prefer to:"
   - Option A: "Be direct and straightforward" → `{directness: 2, empathy: 0, clarity: 1}`
   - Option B: "Consider feelings first" → `{directness: 0, empathy: 2, clarity: 0}`
   - Option C: "Be clear and structured" → `{directness: 1, empathy: 0, clarity: 2}`
   - Option D: "Balance all approaches" → `{directness: 1, empathy: 1, clarity: 1}`

2-6. (Similar structure)

### Sample Answer Set

**User A Answers:**
- Q1: Option A (directness: 2, empathy: 0, clarity: 1)
- Q2: Option A (directness: 2, empathy: 0, clarity: 1)
- Q3: Option C (directness: 1, empathy: 0, clarity: 2)
- Q4: Option A (directness: 2, empathy: 0, clarity: 1)
- Q5: Option C (directness: 1, empathy: 0, clarity: 2)
- Q6: Option A (directness: 2, empathy: 0, clarity: 1)

**Raw Scores:**
- directness: 2+2+1+2+1+2 = 10
- empathy: 0+0+0+0+0+0 = 0
- clarity: 1+1+2+1+2+1 = 8

**Normalization** (assuming max possible: directness=12, empathy=12, clarity=12):
- directness: 10/12 = 0.833
- empathy: 0/12 = 0.000
- clarity: 8/12 = 0.667

**Trait Vector A**: `{directness: 0.833, empathy: 0.000, clarity: 0.667}`

**User B Answers:**
- Q1: Option B (directness: 0, empathy: 2, clarity: 0)
- Q2: Option B (directness: 0, empathy: 2, clarity: 0)
- Q3: Option C (directness: 1, empathy: 0, clarity: 2)
- Q4: Option D (directness: 1, empathy: 1, clarity: 1)
- Q5: Option C (directness: 1, empathy: 0, clarity: 2)
- Q6: Option D (directness: 1, empathy: 1, clarity: 1)

**Raw Scores:**
- directness: 0+0+1+1+1+1 = 4
- empathy: 2+2+0+1+0+1 = 6
- clarity: 0+0+2+1+2+1 = 6

**Normalization**:
- directness: 4/12 = 0.333
- empathy: 6/12 = 0.500
- clarity: 6/12 = 0.500

**Trait Vector B**: `{directness: 0.333, empathy: 0.500, clarity: 0.500}`

### Matching Calculation

**Cosine Similarity:**
- Dot product: (0.833×0.333) + (0.000×0.500) + (0.667×0.500) = 0.278 + 0 + 0.334 = 0.612
- Magnitude A: sqrt(0.833² + 0.000² + 0.667²) = sqrt(0.694 + 0 + 0.445) = sqrt(1.139) = 1.067
- Magnitude B: sqrt(0.333² + 0.500² + 0.500²) = sqrt(0.111 + 0.25 + 0.25) = sqrt(0.611) = 0.782
- Cosine: 0.612 / (1.067 × 0.782) = 0.612 / 0.834 = 0.734

**Match Score**: 100 × 0.734 = **73.4%**

**Breakdown:**
- directness: A=0.833, B=0.333, similarity=0.60 (low - different styles)
- empathy: A=0.000, B=0.500, similarity=0.00 (very different)
- clarity: A=0.667, B=0.500, similarity=0.99 (similar)

## 10. Implementation Notes

### Normalization Strategy

- Min-max normalization per trait based on possible range
- Range calculated from quiz configuration (sum of all possible contributions)
- Ensures all trait values in [0, 1] for consistent matching

### Performance Considerations

- Trait vectors stored as JSON for flexibility
- Indexes on `share_token`, `quiz_id + user_id`, `quiz_version` for fast lookups
- Comparison results cached (optional) to avoid recalculation
- Rate limiting prevents abuse

### Extensibility

- New aspects can be added without schema changes (just new records)
- New traits can be added to quizzes without breaking existing results
- Algorithm selection allows experimentation with different matching approaches
- Version system allows quiz evolution while maintaining backward compatibility

---

**Document Version**: 1.0  
**Last Updated**: 2024-01-15  
**Author**: Match Me Development Team


