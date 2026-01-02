/**
 * Framework-agnostic AJAX client for quiz submission and matching.
 */
(function() {
    'use strict';

    const API_BASE = '/wp-json/match-me/v1';

    /**
     * Submit quiz answers and get result.
     *
     * @param {string} quizId - Quiz identifier
     * @param {Array<{question_id: string, option_id: string, value?: number}>} answers - User answers
     * @param {Object} options - Additional options (share_mode, etc.)
     * @returns {Promise<Object>} Result with trait_vector, share_token, etc.
     */
    function submitQuiz(quizId, answers, options = {}) {
        const url = `${API_BASE}/quiz/${encodeURIComponent(quizId)}/submit`;
        const body = {
            answers: answers,
            share_mode: options.share_mode || 'share_match',
            anonymous_meta: options.anonymous_meta || {}
        };

        const nonce = (window.matchMeQuizVars && window.matchMeQuizVars.nonce) || (window.cqVars && window.cqVars.nonce) || '';

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify(body)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => Promise.reject(err));
            }
            return response.json();
        });
    }

    /**
     * Get result by share token.
     *
     * @param {string} shareToken - Share token
     * @returns {Promise<Object>} Result summary
     */
    function getResult(shareToken) {
        const url = `${API_BASE}/result/${encodeURIComponent(shareToken)}`;

        return fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => Promise.reject(err));
            }
            return response.json();
        });
    }

    /**
     * Compare results.
     *
     * @param {string} shareToken - Share token of first result
     * @param {Object} options - Either {answers, quiz_id} or {result_id}
     * @returns {Promise<Object>} Match result with score and breakdown
     */
    function compareResults(shareToken, options) {
        const url = `${API_BASE}/result/${encodeURIComponent(shareToken)}/compare`;
        const body = {};

        if (options.result_id) {
            body.result_id = options.result_id;
        } else if (options.answers && options.quiz_id) {
            body.answers = options.answers;
            body.quiz_id = options.quiz_id;
        } else {
            return Promise.reject(new Error('Either result_id or answers+quiz_id required'));
        }

        if (options.algorithm) {
            body.algorithm = options.algorithm;
        }

        const nonce = (window.matchMeQuizVars && window.matchMeQuizVars.nonce) || (window.cqVars && window.cqVars.nonce) || '';

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify(body)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => Promise.reject(err));
            }
            return response.json();
        });
    }

    /**
     * Get comparison by share token.
     *
     * @param {string} shareToken
     * @returns {Promise<Object>}
     */
    function getComparison(shareToken) {
        const url = `${API_BASE}/comparison/${encodeURIComponent(shareToken)}`;

        return fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => Promise.reject(err));
            }
            return response.json();
        });
    }

    // Export to global scope
    window.MatchMeQuiz = {
        submitQuiz: submitQuiz,
        getResult: getResult,
        compareResults: compareResults,
        getComparison: getComparison
    };
})();


