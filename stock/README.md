# Store Transfers - CIS Module

## Overview
Store Transfer management system for CIS (Central Information System) with enhanced collaborative editing features.

## Features
- **Enhanced Store Transfer Header**: Beautiful purple gradient with destination emphasis
- **Lock System**: Collaborative editing with ownership requests 
- **Real-time Notifications**: Live ownership request system
- **Compact Countdown Timer**: Persistent request tracking in header
- **Rich Outlet Data**: Full store information with contact details and directions

## Components
- `pack.view.php` - Main Store Transfer interface with enhanced header
- `pack-lock.js` - Lock system with ownership requests and notifications
- `lock_request.php` - API for ownership request creation
- Database tables for lock management and request tracking

## Recent Updates
- ✅ Fixed SQL truncation error for client fingerprints
- ✅ Added compact countdown timer in header bar
- ✅ Implemented real-time ownership request notifications  
- ✅ Added request persistence across page refreshes
- ✅ Enhanced header design with destination emphasis

## Database Schema
- `transfer_pack_locks` - Active transfer locks
- `transfer_pack_lock_requests` - Ownership request queue

## Installation
1. Ensure database tables are created with VARCHAR(255) client_fingerprint fields
2. Update asset version numbers in pack.view.php for cache busting
3. Test ownership request functionality between users

## Development
- Lock system polls every 5 seconds for real-time updates
- Ownership requests expire after 60 seconds  
- Compact timer shows in header with localStorage persistence
- Full notification system for collaborative editing