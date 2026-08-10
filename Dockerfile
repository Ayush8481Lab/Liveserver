# Use the official Puppeteer image which includes all required OS dependencies
FROM ghcr.io/puppeteer/puppeteer:latest

# Tell Puppeteer to use the installed Chrome browser rather than downloading its own
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable

WORKDIR /usr/src/app

# Switch to root user to install our NPM packages
USER root

# Copy package files and install dependencies
COPY package*.json ./
RUN npm install

# Copy the rest of the application
COPY . .

# Switch back to the safe, non-root user provided by the base image
USER pptruser

# Expose the port Render expects
EXPOSE 3000

# Start the Express server
CMD ["npm", "start"]
