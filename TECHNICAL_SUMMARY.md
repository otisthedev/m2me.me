# Technical Summary: Quiz Matching System

**For Product Owners & Stakeholders**

## How Matching Works

### Overview

The matching system compares users based on their quiz results. Each quiz measures personality traits (like "directness", "empathy", "clarity") and converts answers into a **trait vector** - a set of numbers between 0 and 1 representing where the user falls on each trait.

### The Matching Process

1. **User Takes Quiz**: Answers questions about their communication style, conflict resolution, etc.
2. **Backend Calculation**: Server calculates trait vector from answers (all calculation happens server-side for security and accuracy)
3. **Normalization**: Traits are normalized to 0-1 scale for fair comparison
4. **Comparison**: When two users want to match, the system compares their trait vectors using **cosine similarity**
5. **Match Score**: Returns a percentage (0-100%) showing how similar the users are

### Example

**User A's Results:**
- Directness: 75%
- Empathy: 60%
- Clarity: 90%

**User B's Results:**
- Directness: 70%
- Empathy: 65%
- Clarity: 85%

**Match Score: 87.5%** - These users have very similar communication styles!

### Why Cosine Similarity?

- Works well with normalized data (0-1 scale)
- Focuses on **direction** rather than absolute values (two people can both be high in empathy and match well, even if exact percentages differ)
- Industry-standard algorithm used in recommendation systems
- Produces intuitive 0-100% scores

## Modular Aspect System

### What Are Aspects?

Each quiz belongs to an **aspect** - a category of personality or behavior:

- **Communication Style**: How people express themselves
- **Conflict Resolution**: How people handle disagreements
- **Decision Making**: How people make choices
- (More aspects can be added)

### How It Works

1. Each quiz measures traits within one aspect
2. Users can take multiple quizzes (one per aspect)
3. When matching, the system compares users on **shared aspects only**
4. If User A has completed "Communication" and "Conflict" but User B only has "Communication", they match only on Communication

### Benefits

- **Flexible**: Add new aspects without changing existing code
- **Partial Matching**: Users don't need to complete all aspects to get matches
- **Weighted**: Different aspects can have different importance in overall matching

## Adding New Aspects/Quizzes

### Adding a New Aspect

1. **Create Aspect Record** (via admin or database):
   - Slug: `new-aspect-name`
   - Title: "New Aspect Name"
   - Weight: 1.0 (default, can be adjusted)

2. **Create Quiz JSON File**:
   - Place in `wp-content/X-quizzes/`
   - Set `"aspect": "new-aspect-name"` in meta
   - Define questions with trait mappings

3. **That's It!** The system automatically:
   - Links quiz to aspect
   - Enables matching on this aspect
   - Includes it in overall match calculations

### Adding a New Quiz to Existing Aspect

1. **Create Quiz JSON File**:
   - Use same `"aspect"` value as other quizzes in that aspect
   - Follow format from `communication-style-v1.json`

2. **Quiz is Immediately Available**:
   - Users can take it via API
   - Results stored with aspect linkage
   - Matching works automatically

### Quiz Format Requirements

- **Questions**: Array of question objects
- **Trait Map**: Each answer option maps to trait contributions
- **Traits**: Define what traits this quiz measures
- **Version**: Semantic versioning (e.g., "1.0", "1.1")

See `quizzes/communication-style-v1.json` for complete example.

## Security & Privacy

### Share Tokens

- **32+ character random strings** (unguessable)
- **Permanent** until explicitly revoked
- **Three modes**:
  - `private`: No sharing
  - `share`: View-only link
  - `share_match`: Others can view and compare

### Data Privacy

- **Raw answers never exposed** to external viewers
- Only trait vectors and summaries shared
- Users control their own sharing settings

### Rate Limiting

- Compare endpoint limited to **10 requests per hour per IP**
- Prevents abuse and ensures fair usage

## Versioning & Migration

### Why Versioning?

Quizzes may evolve over time (new questions, changed trait definitions). Versioning ensures:

- Old results remain valid
- Users can retake to get updated results
- Matching only occurs between compatible versions

### How It Works

1. Each result stores the quiz version it was created with
2. When matching, system checks version compatibility
3. **Automatic migration** attempted if trait names unchanged
4. If incompatible, users see message to retake quiz

### Adding New Quiz Version

1. Update quiz JSON file
2. Increment version number (e.g., "1.0" → "1.1")
3. System handles version checking automatically
4. Old results remain accessible but may need retake for matching

## Performance & Scalability

### Database Design

- **Indexed for speed**: Share tokens, user+quiz combinations, versions
- **JSON storage**: Flexible trait vectors without schema changes
- **Efficient queries**: Optimized for common operations

### Caching

- Comparison results can be cached (optional)
- Rate limiting uses WordPress transients (fast)
- No external dependencies required

### Scalability

- **Stateless API**: Easy to scale horizontally
- **Database-backed**: Reliable and persistent
- **WordPress-native**: Leverages existing infrastructure

## Admin Controls

### Login Requirement Toggle

- **Location**: WordPress admin settings
- **Option**: `match_me_require_login_for_results`
- **Effect**: When enabled, users must log in to view/share results

### Aspect Weights

- Configure in `match_me_aspects` table
- Default: 1.0 (equal weight)
- Higher weight = more important in overall matching

### Token Management

- View all share tokens in admin
- Revoke tokens individually
- Monitor usage and access patterns

## Future Enhancements

### Possible Additions

- **Batch Matching**: Compare one result against many
- **Recommendation Engine**: Suggest compatible users
- **Analytics Dashboard**: Match statistics and trends
- **Export/Import**: Bulk operations for admins
- **Webhooks**: Notify external systems of matches

### Extensibility

The system is designed for easy extension:
- New matching algorithms can be added
- Additional data fields can be stored
- Custom comparison logic can be implemented
- Integration with external services is straightforward

---

**Questions?** Refer to:
- `README.md` for technical details
- `API.md` for endpoint documentation
- `DESIGN_DOC.md` for complete system design


