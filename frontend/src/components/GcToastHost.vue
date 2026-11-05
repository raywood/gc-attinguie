<script setup>
import { gcToasts, dismissGcToast } from '../services/gc-toasts'
</script>

<template>
  <div class="gc-toast-region" aria-label="Notifications de validation">
    <TransitionGroup tag="div" class="gc-toast-stack" name="gc-toast">
      <div
        v-for="toast in gcToasts"
        :key="toast.id"
        class="gc-toast-card"
        :class="`gc-toast-card--${toast.state}`"
        :role="toast.state === 'error' ? 'alert' : 'status'"
        :aria-live="toast.state === 'error' ? 'assertive' : 'polite'"
      >
        <span class="gc-toast-mark" aria-hidden="true">
          <span v-if="toast.state === 'loading'" class="gc-toast-spinner"></span>
          <span v-else-if="toast.state === 'success'">✓</span>
          <span v-else>!</span>
        </span>
        <span class="gc-toast-message">{{ toast.title }}</span>
        <button type="button" class="gc-toast-close" aria-label="Fermer le toast" @click="dismissGcToast(toast.id)">×</button>
        <div class="gc-toast-progress" aria-hidden="true">
          <div
            :key="toast.phase"
            class="gc-toast-bar"
            :style="{ '--gc-toast-time': `${toast.duration}ms` }"
          ></div>
        </div>
      </div>
    </TransitionGroup>
  </div>
</template>
