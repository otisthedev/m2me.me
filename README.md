# Match Me Quiz & Matching System

A modular, aspect-based quiz and matching platform with backend calculation, secure sharing, and comprehensive matching algorithms.

## Overview

This system provides:

- **Backend Calculation**: All quiz scoring and trait vector generation happens server-side
- **Modular Aspects**: Each quiz belongs to an aspect (e.g., "communication-style", "conflict-resolution")
- **Secure Sharing**: Unguessable share tokens for result sharing
- **Matching Algorithm**: Weighted cosine similarity for comparing user results
- **Versioning**: Quiz versioning with migration support
- **Mobile-First UI**: Responsive, touch-friendly interface

## Architecture

### Core Components

1. **QuizCalculator** (`src/Quiz/QuizCalculator.php`)
   - Calculates trait vectors from answers
   - Normalizes vectors to [0, 1] range
   - Computes matches using cosine similarity, Euclidean distance, or absolute difference

2. **MatchingService** (`src/Quiz/MatchingService.php`)
   - Orchestrates matching between results
   - Handles partial aspect matching
   - Generates match breakdowns

3. **QuizApiController** (`src/Wp/Api/QuizApiController.php`)
   - WordPress REST API endpoints
   - Handles quiz submission, result retrieval, comparison, and token revocation
   - Implements rate limiting

4. **Database Tables**
   - `match_me_aspects`: Aspect definitions
   - `match_me_quizzes`: Quiz metadata
   - `match_me_questions`: Question definitions with trait mappings
   - `match_me_results`: User results with trait vectors
   - `match_me_comparisons`: Stored comparisons

## Installation

1. Ensure WordPress is installed and the theme is activated
2. The database tables will be created automatically on theme activation
3. Place quiz JSON files in `wp-content/X-quizzes/` directory
4. Quiz files should follow the format specified in `quizzes/communication-style-v1.json`

## API Endpoints

See [API.md](API.md) for detailed endpoint documentation.

### Quick Reference

- `POST /wp-json/match-me/v1/quiz/{quiz_id}/submit` - Submit quiz answers
- `GET /wp-json/match-me/v1/result/{share_token}` - Get result by share token
- `POST /wp-json/match-me/v1/result/{share_token}/compare` - Compare results
- `POST /wp-json/match-me/v1/result/{result_id}/revoke` - Revoke share token

## Quiz Format

Quizzes are defined as JSON files with the following structure:

```json
{
  "meta": {
    "title": "Quiz Title",
    "description": "Quiz description",
    "version": "1.0",
    "aspect": "aspect-slug"
  },
  "questions": [
    {
      "id": "q1",
      "text": "Question text",
      "weight": 1.0,
      "options_json": [
        {"id": "opt_1", "text": "Option 1"}
      ],
      "trait_map": {
        "opt_1": {"trait1": 2, "trait2": 0}
      }
    }
  ],
  "traits": {
    "trait1": {
      "label": "Trait Label",
      "description": "Trait description"
    }
  }
}
```

## Testing

### Unit Tests

Run PHPUnit tests:

```bash
phpunit tests/Quiz/QuizCalculatorTest.php
```

### Integration Tests

See `tests/Integration/QuizFlowTest.php` for end-to-end testing.

### Manual Testing

1. Submit a quiz via API
2. Retrieve result using share token
3. Compare two results
4. Verify match scores and breakdowns

## Configuration

### Admin Settings

- **Login Requirement**: Toggle via WordPress option `match_me_require_login_for_results`
- **Aspect Weights**: Configure in `match_me_aspects` table
- **Rate Limiting**: Configured in `QuizApiController` (default: 10 requests/hour)

### Environment Variables

- `MATCH_ME_VERSION`: Theme version
- Quiz directory: `WP_CONTENT_DIR . '/X-quizzes/'`

## Deployment

### Rollout Steps

1. **Backup Database**: Ensure current quiz results are backed up
2. **Run Migrations**: Tables created automatically on theme activation
3. **Test API Endpoints**: Verify all endpoints work correctly
4. **Update Frontend**: Ensure frontend JavaScript uses new API endpoints
5. **Monitor**: Watch for errors in WordPress debug log

### Rollback Plan

If issues occur:

1. Revert theme to previous version
2. Old `wp_cq_quiz_results` table remains intact
3. Frontend can fall back to old AJAX handlers if needed
4. New tables can be dropped if necessary (data migration not required for rollback)

## Troubleshooting

### Common Issues

**API returns 404:**
- Ensure permalinks are flushed: `Settings > Permalinks > Save Changes`
- Check that REST API is enabled

**Calculations don't match expected values:**
- Verify quiz JSON format matches specification
- Check trait_map structure in questions
- Ensure normalization ranges are calculated correctly

**Share tokens not working:**
- Verify token is not revoked (`revoked_at` is NULL)
- Check `share_mode` allows the requested operation
- Ensure token is 32+ characters

## Development

### Adding a New Aspect

1. Insert record into `match_me_aspects` table
2. Create quiz JSON files with `"aspect"` matching aspect slug
3. Quizzes will automatically link to aspect via `aspect_id`

### Adding a New Quiz

1. Create JSON file in `wp-content/X-quizzes/`
2. Follow format from `communication-style-v1.json`
3. Ensure `trait_map` is correctly structured
4. Quiz will be available via API using file name (without .json)

### Modifying Matching Algorithm

1. Update `QuizCalculator::computeMatch()` method
2. Add new algorithm option in `QuizApiController`
3. Update tests to verify new algorithm
4. Document algorithm in DESIGN_DOC.md

## License

GPL v2 or later

## Support

For issues or questions, refer to:
- Design Document: `DESIGN_DOC.md`
- API Documentation: `API.md`
- Technical Summary: `TECHNICAL_SUMMARY.md`


